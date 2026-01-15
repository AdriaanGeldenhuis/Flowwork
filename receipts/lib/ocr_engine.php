<?php
/**
 * OCR Engine — Pluggable OCR provider
 * Supports: Tesseract (local), Google Vision, AWS Textract, Azure Form Recognizer
 */

class OCREngine {
    private $provider;
    private $config;

    public function __construct($provider = 'tesseract', $config = []) {
        $this->provider = $provider;
        $this->config = $config;
    }

    public function parseReceipt($filePath, $mimeType) {
        switch ($this->provider) {
            case 'google':
                return $this->parseWithGoogleVision($filePath, $mimeType);
            case 'aws':
                return $this->parseWithAWSTextract($filePath, $mimeType);
            case 'azure':
                return $this->parseWithAzure($filePath, $mimeType);
            case 'tesseract':
            default:
                return $this->parseWithTesseract($filePath, $mimeType);
        }
    }

    // ========== TESSERACT (LOCAL) ==========
    private function parseWithTesseract($filePath, $mimeType) {
        // Convert PDF to image if needed
        if ($mimeType === 'application/pdf') {
            $imagePath = $this->convertPDFToImage($filePath);
        } else {
            $imagePath = $filePath;
        }

        // Run tesseract
        $outputBase = sys_get_temp_dir() . '/ocr_' . uniqid();
        exec("tesseract " . escapeshellarg($imagePath) . " " . escapeshellarg($outputBase) . " 2>&1", $output, $returnVar);

        if ($returnVar !== 0) {
            error_log('Tesseract failed: ' . implode("\n", $output));
            return $this->getDummyResult();
        }

        $text = file_get_contents($outputBase . '.txt');
        unlink($outputBase . '.txt');

        if ($mimeType === 'application/pdf' && file_exists($imagePath)) {
            unlink($imagePath);
        }

        return $this->extractFieldsFromText($text);
    }

    private function convertPDFToImage($pdfPath) {
        $imagePath = sys_get_temp_dir() . '/pdf_page_' . uniqid() . '.png';
        exec("convert -density 300 " . escapeshellarg($pdfPath) . "[0] -quality 90 " . escapeshellarg($imagePath) . " 2>&1", $output, $returnVar);
        
        if ($returnVar !== 0 || !file_exists($imagePath)) {
            error_log('PDF to image conversion failed: ' . implode("\n", $output));
            return $pdfPath;
        }

        return $imagePath;
    }

    // ========== GOOGLE VISION API ==========
    private function parseWithGoogleVision($filePath, $mimeType) {
        $apiKey = $this->config['google_api_key'] ?? '';
        if (!$apiKey) {
            error_log('Google Vision API key not configured');
            return $this->getDummyResult();
        }

        $imageContent = base64_encode(file_get_contents($filePath));

        $payload = [
            'requests' => [
                [
                    'image' => ['content' => $imageContent],
                    'features' => [
                        ['type' => 'DOCUMENT_TEXT_DETECTION']
                    ]
                ]
            ]
        ];

        $ch = curl_init('https://vision.googleapis.com/v1/images:annotate?key=' . $apiKey);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log('Google Vision API failed: ' . $response);
            return $this->getDummyResult();
        }

        $data = json_decode($response, true);
        $text = $data['responses'][0]['fullTextAnnotation']['text'] ?? '';

        return $this->extractFieldsFromText($text);
    }

    // ========== AWS TEXTRACT ==========
    private function parseWithAWSTextract($filePath, $mimeType) {
        require_once __DIR__ . '/../../vendor/autoload.php'; // AWS SDK

        $awsKey = $this->config['aws_access_key'] ?? '';
        $awsSecret = $this->config['aws_secret_key'] ?? '';
        $awsRegion = $this->config['aws_region'] ?? 'us-east-1';

        if (!$awsKey || !$awsSecret) {
            error_log('AWS credentials not configured');
            return $this->getDummyResult();
        }

        try {
            $client = new \Aws\Textract\TextractClient([
                'version' => 'latest',
                'region' => $awsRegion,
                'credentials' => [
                    'key' => $awsKey,
                    'secret' => $awsSecret
                ]
            ]);

            $result = $client->analyzeExpense([
                'Document' => [
                    'Bytes' => file_get_contents($filePath)
                ]
            ]);

            return $this->parseAWSTextractResponse($result);

        } catch (Exception $e) {
            error_log('AWS Textract failed: ' . $e->getMessage());
            return $this->getDummyResult();
        }
    }

    private function parseAWSTextractResponse($result) {
        $data = [
            'vendor_name' => null,
            'vendor_vat' => null,
            'invoice_number' => null,
            'invoice_date' => null,
            'currency' => 'ZAR',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'lines' => [],
            'confidence' => 0.85
        ];

        foreach ($result['ExpenseDocuments'] as $doc) {
            foreach ($doc['SummaryFields'] as $field) {
                $type = $field['Type']['Text'] ?? '';
                $value = $field['ValueDetection']['Text'] ?? '';

                switch (strtoupper($type)) {
                    case 'VENDOR_NAME':
                    case 'NAME':
                        $data['vendor_name'] = $value;
                        break;
                    case 'INVOICE_RECEIPT_ID':
                    case 'INVOICE_NUMBER':
                        $data['invoice_number'] = $value;
                        break;
                    case 'INVOICE_RECEIPT_DATE':
                    case 'DATE':
                        $data['invoice_date'] = date('Y-m-d', strtotime($value));
                        break;
                    case 'TOTAL':
                        $data['total'] = (float)preg_replace('/[^0-9.]/', '', $value);
                        break;
                    case 'SUBTOTAL':
                        $data['subtotal'] = (float)preg_replace('/[^0-9.]/', '', $value);
                        break;
                    case 'TAX':
                        $data['tax'] = (float)preg_replace('/[^0-9.]/', '', $value);
                        break;
                }
            }

            // Line items
            foreach ($doc['LineItemGroups'] as $group) {
                foreach ($group['LineItems'] as $item) {
                    $line = [
                        'description' => '',
                        'qty' => 1,
                        'unit' => 'ea',
                        'unit_price' => 0,
                        'tax_rate' => 15.00
                    ];

                    foreach ($item['LineItemExpenseFields'] as $field) {
                        $type = $field['Type']['Text'] ?? '';
                        $value = $field['ValueDetection']['Text'] ?? '';

                        switch (strtoupper($type)) {
                            case 'ITEM':
                            case 'DESCRIPTION':
                                $line['description'] = $value;
                                break;
                            case 'QUANTITY':
                                $line['qty'] = (float)$value;
                                break;
                            case 'PRICE':
                            case 'UNIT_PRICE':
                                $line['unit_price'] = (float)preg_replace('/[^0-9.]/', '', $value);
                                break;
                        }
                    }

                    if ($line['description']) {
                        $data['lines'][] = $line;
                    }
                }
            }
        }

        // Calculate missing values
        if (!$data['subtotal'] && $data['total'] && $data['tax']) {
            $data['subtotal'] = $data['total'] - $data['tax'];
        }
        if (!$data['tax'] && $data['total'] && $data['subtotal']) {
            $data['tax'] = $data['total'] - $data['subtotal'];
        }
        if (!$data['total'] && $data['subtotal']) {
            $data['total'] = $data['subtotal'] + $data['tax'];
        }

        return $data;
    }

    // ========== AZURE FORM RECOGNIZER ==========
    private function parseWithAzure($filePath, $mimeType) {
        $endpoint = $this->config['azure_endpoint'] ?? '';
        $apiKey = $this->config['azure_api_key'] ?? '';

        if (!$endpoint || !$apiKey) {
            error_log('Azure credentials not configured');
            return $this->getDummyResult();
        }

        $url = $endpoint . '/formrecognizer/documentModels/prebuilt-invoice:analyze?api-version=2023-07-31';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, file_get_contents($filePath));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $apiKey,
            'Content-Type: ' . $mimeType
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $operationLocation = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($httpCode !== 202) {
            error_log('Azure Form Recognizer failed: ' . $response);
            return $this->getDummyResult();
        }

        // Poll for result
        sleep(3);
        $ch = curl_init($operationLocation);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Ocp-Apim-Subscription-Key: ' . $apiKey
        ]);

        $result = curl_exec($ch);
        curl_close($ch);

        $data = json_decode($result, true);
        return $this->parseAzureResponse($data);
    }

    private function parseAzureResponse($data) {
        $fields = $data['analyzeResult']['documents'][0]['fields'] ?? [];

        return [
            'vendor_name' => $fields['VendorName']['content'] ?? null,
            'vendor_vat' => $fields['VendorTaxId']['content'] ?? null,
            'invoice_number' => $fields['InvoiceId']['content'] ?? null,
            'invoice_date' => isset($fields['InvoiceDate']['content']) ? date('Y-m-d', strtotime($fields['InvoiceDate']['content'])) : null,
            'currency' => $fields['CurrencyCode']['content'] ?? 'ZAR',
            'subtotal' => (float)($fields['SubTotal']['content'] ?? 0),
            'tax' => (float)($fields['TotalTax']['content'] ?? 0),
            'total' => (float)($fields['InvoiceTotal']['content'] ?? 0),
            'lines' => $this->parseAzureLineItems($fields['Items']['valueArray'] ?? []),
            'confidence' => 0.90
        ];
    }

    private function parseAzureLineItems($items) {
        $lines = [];
        foreach ($items as $item) {
            $fields = $item['valueObject'] ?? [];
            $lines[] = [
                'description' => $fields['Description']['content'] ?? '',
                'qty' => (float)($fields['Quantity']['content'] ?? 1),
                'unit' => $fields['Unit']['content'] ?? 'ea',
                'unit_price' => (float)($fields['UnitPrice']['content'] ?? 0),
                'tax_rate' => 15.00
            ];
        }
        return $lines;
    }

    // ========== TEXT PARSING (FALLBACK) ==========
    private function extractFieldsFromText($text) {
        $data = [
            'vendor_name' => null,
            'vendor_vat' => null,
            'invoice_number' => null,
            'invoice_date' => null,
            'currency' => 'ZAR',
            'subtotal' => 0,
            'tax' => 0,
            'total' => 0,
            'lines' => [],
            'confidence' => 0.70
        ];

        // Extract invoice number (patterns: INV-123, #123, Invoice: 123)
        if (preg_match('/(?:invoice|inv|#)[:\s]*([A-Z0-9-]+)/i', $text, $m)) {
            $data['invoice_number'] = trim($m[1]);
        }

        // Extract date (patterns: 2025-01-21, 21/01/2025, Jan 21 2025)
        if (preg_match('/\b(\d{4}[-\/]\d{2}[-\/]\d{2})\b/', $text, $m)) {
            $data['invoice_date'] = $m[1];
        } elseif (preg_match('/\b(\d{2}[-\/]\d{2}[-\/]\d{4})\b/', $text, $m)) {
            $data['invoice_date'] = date('Y-m-d', strtotime($m[1]));
        }

        // Extract VAT number (SA format: 4123456789)
        if (preg_match('/\b(4\d{9})\b/', $text, $m)) {
            $data['vendor_vat'] = $m[1];
        }

        // Extract total (patterns: Total: R1234.56, Total 1234.56, R 1,234.56)
        if (preg_match('/total[:\s]*R?\s*([\d,]+\.?\d*)/i', $text, $m)) {
            $data['total'] = (float)str_replace(',', '', $m[1]);
        }

        // Extract VAT/Tax
        if (preg_match('/(?:vat|tax)[:\s]*R?\s*([\d,]+\.?\d*)/i', $text, $m)) {
            $data['tax'] = (float)str_replace(',', '', $m[1]);
        }

        // Calculate subtotal
        if ($data['total'] && $data['tax']) {
            $data['subtotal'] = $data['total'] - $data['tax'];
        } elseif ($data['total']) {
            // Assume 15% VAT
            $data['subtotal'] = round($data['total'] / 1.15, 2);
            $data['tax'] = $data['total'] - $data['subtotal'];
        }

        // Extract vendor name (first line usually)
        $lines = explode("\n", $text);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strlen($line) > 3 && !preg_match('/invoice|tax|total|amount/i', $line)) {
                $data['vendor_name'] = $line;
                break;
            }
        }

        return $data;
    }

    // ========== DUMMY RESULT (FALLBACK) ==========
    private function getDummyResult() {
        return [
            'vendor_name' => 'Unknown Supplier',
            'vendor_vat' => null,
            'invoice_number' => 'INV-' . rand(1000, 9999),
            'invoice_date' => date('Y-m-d'),
            'currency' => 'ZAR',
            'subtotal' => rand(1000, 10000),
            'tax' => 0,
            'total' => 0,
            'lines' => [
                [
                    'description' => 'Item 1',
                    'qty' => 1,
                    'unit' => 'ea',
                    'unit_price' => rand(100, 500),
                    'tax_rate' => 15.00
                ]
            ],
            'confidence' => 0.00
        ];
    }
}