<?php
// /crm/test.php - Test all endpoints
require_once __DIR__ . '/../init.php';
require_once __DIR__ . '/../auth_gate.php';

$companyId = $_SESSION['company_id'];
$userId = $_SESSION['user_id'];

$endpoints = [
    'search' => '/crm/ajax/search.php?type=supplier',
    'compliance_check' => '/crm/ajax/compliance_check.php?account_id=1',
    'tag_suggest' => '/crm/ajax/tag_suggest.php?q=test',
    'account_timeline' => '/crm/ajax/account_timeline.php?account_id=1'
];

?>
<!DOCTYPE html>
<html>
<head>
    <title>CRM Endpoint Tester</title>
    <style>
        body { font-family: monospace; padding: 20px; }
        .test { margin: 20px 0; padding: 15px; border: 1px solid #ccc; }
        .pass { background: #d4edda; }
        .fail { background: #f8d7da; }
        button { padding: 10px 20px; margin: 5px; }
    </style>
</head>
<body>
    <h1>CRM AJAX Endpoint Tester</h1>
    <button onclick="testAll()">Test All Endpoints</button>
    <div id="results"></div>

    <script>
    async function testEndpoint(name, url) {
        try {
            const res = await fetch(url);
            const data = await res.json();
            return {
                name: name,
                url: url,
                status: res.status,
                ok: data.ok || false,
                data: data
            };
        } catch (err) {
            return {
                name: name,
                url: url,
                status: 'error',
                ok: false,
                error: err.message
            };
        }
    }

    async function testAll() {
        const endpoints = <?= json_encode($endpoints) ?>;
        const results = document.getElementById('results');
        results.innerHTML = '<p>Testing...</p>';

        const tests = [];
        for (const [name, url] of Object.entries(endpoints)) {
            tests.push(testEndpoint(name, url));
        }

        const allResults = await Promise.all(tests);
        
        let html = '';
        allResults.forEach(result => {
            const cssClass = result.ok ? 'pass' : 'fail';
            html += `
                <div class="test ${cssClass}">
                    <h3>${result.name} - ${result.ok ? '✓ PASS' : '✗ FAIL'}</h3>
                    <p>URL: ${result.url}</p>
                    <p>Status: ${result.status}</p>
                    <pre>${JSON.stringify(result.data || result.error, null, 2)}</pre>
                </div>
            `;
        });

        results.innerHTML = html;
    }
    </script>
</body>
</html>