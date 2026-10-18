<?php
// projects/lib/Templates.php
//
// Board blueprints (groups + columns) and the project templates that combine
// them. Column configs use the shapes Columns::config() reads.

final class Templates
{
    private const STATUS_WORK = ['labels' => [
        ['key' => 'todo', 'label' => 'To do', 'color' => '#85766b'],
        ['key' => 'working', 'label' => 'Working on it', 'color' => '#d39a32'],
        ['key' => 'stuck', 'label' => 'Stuck', 'color' => '#c2412f'],
        ['key' => 'done', 'label' => 'Done', 'color' => '#2f8a5f', 'done' => true],
    ]];

    private const UNITS = ['options' => ['each', 'm', 'm²', 'm³', 'kg', 't', 'litre', 'hours', 'days', 'lump sum']];

    /** Board blueprints shown in the "New board" dialog. */
    public static function boards(): array
    {
        return [
            'tasks' => [
                'title' => 'Tasks', 'type' => 'general', 'icon' => 'check-square', 'noun' => 'task',
                'description' => 'Everyday work with owners, status and due dates.',
                'groups' => [['This week', '#2f5c84'], ['Next week', '#7a5ab8'], ['Later', '#b08d57']],
                'columns' => [
                    ['Owner', 'people', 150],
                    ['Status', 'status', 150, self::STATUS_WORK],
                    ['Due date', 'date', 130],
                    ['Priority', 'priority', 130],
                    ['Notes', 'text', 220],
                ],
            ],
            'schedule' => [
                'title' => 'Programme', 'type' => 'schedule', 'icon' => 'timeline', 'noun' => 'activity',
                'description' => 'A construction programme: phases, timelines, dependencies and progress.',
                'view' => 'gantt',
                'groups' => [['Site establishment', '#5b6670'], ['Substructure', '#b08d57'], ['Superstructure', '#7a1f30'], ['Finishes', '#2f5c84'], ['Handover', '#2f7457']],
                'columns' => [
                    ['Owner', 'people', 140],
                    ['Status', 'status', 150, self::STATUS_WORK],
                    ['Timeline', 'timeline', 200],
                    ['Work days', 'formula', 110, ['formula' => 'WORKDAYS({Timeline.start}, {Timeline.end})', 'precision' => 0, 'agg' => 'sum']],
                    ['Progress', 'progress', 140, ['mode' => 'manual']],
                ],
            ],
            'costing' => [
                'title' => 'Costing', 'type' => 'costing', 'icon' => 'wallet', 'noun' => 'line',
                'description' => 'A bill of quantities: quantities × rates with totals per section.',
                'groups' => [['Preliminaries', '#5b6670'], ['Materials', '#b08d57'], ['Labour', '#2f5c84'], ['Plant & equipment', '#7a5ab8'], ['Subcontractors', '#7a1f30']],
                'columns' => [
                    ['Supplier', 'supplier', 170],
                    ['Qty', 'number', 90, ['format' => 'decimal', 'precision' => 2, 'agg' => 'sum']],
                    ['Unit', 'dropdown', 100, self::UNITS],
                    ['Rate', 'number', 120, ['format' => 'currency', 'precision' => 2, 'agg' => 'avg', 'unit' => 'R']],
                    ['Total', 'formula', 140, ['formula' => '{Qty} * {Rate}', 'precision' => 2, 'agg' => 'sum', 'format' => 'currency', 'unit' => 'R']],
                    ['Payment', 'status', 140, ['labels' => [
                        ['key' => 'not ordered', 'label' => 'Not ordered', 'color' => '#85766b'],
                        ['key' => 'ordered', 'label' => 'Ordered', 'color' => '#2f5c84'],
                        ['key' => 'invoiced', 'label' => 'Invoiced', 'color' => '#d39a32'],
                        ['key' => 'paid', 'label' => 'Paid', 'color' => '#2f8a5f', 'done' => true],
                    ]]],
                ],
            ],
            'procurement' => [
                'title' => 'Procurement', 'type' => 'procurement', 'icon' => 'truck', 'noun' => 'order',
                'description' => 'Quotes, orders and deliveries, supplier by supplier.',
                'groups' => [['To order', '#b08d57'], ['Ordered', '#2f5c84'], ['Delivered', '#2f7457']],
                'columns' => [
                    ['Supplier', 'supplier', 170],
                    ['Status', 'status', 150, ['labels' => [
                        ['key' => 'quote', 'label' => 'Request quote', 'color' => '#85766b'],
                        ['key' => 'quoted', 'label' => 'Quoted', 'color' => '#7a5ab8'],
                        ['key' => 'ordered', 'label' => 'Ordered', 'color' => '#2f5c84'],
                        ['key' => 'backorder', 'label' => 'Back-order', 'color' => '#c2412f'],
                        ['key' => 'delivered', 'label' => 'Delivered', 'color' => '#2f8a5f', 'done' => true],
                    ]]],
                    ['Qty', 'number', 90, ['format' => 'decimal', 'precision' => 2, 'agg' => 'sum']],
                    ['Delivery date', 'date', 130],
                    ['Cost', 'number', 130, ['format' => 'currency', 'precision' => 2, 'agg' => 'sum', 'unit' => 'R']],
                    ['Reference', 'text', 140],
                    ['Documents', 'files', 140],
                ],
            ],
            'snags' => [
                'title' => 'Snags & quality', 'type' => 'quality', 'icon' => 'target', 'noun' => 'snag',
                'description' => 'Defects and quality checks with photos, owners and sign-off.',
                'groups' => [['Open', '#c2412f'], ['Signed off', '#2f7457']],
                'columns' => [
                    ['Location', 'text', 160],
                    ['Assigned', 'people', 140],
                    ['Severity', 'priority', 130],
                    ['Status', 'status', 150, ['labels' => [
                        ['key' => 'open', 'label' => 'Open', 'color' => '#c2412f'],
                        ['key' => 'progress', 'label' => 'In progress', 'color' => '#d39a32'],
                        ['key' => 'fixed', 'label' => 'Fixed', 'color' => '#2f5c84'],
                        ['key' => 'verified', 'label' => 'Verified', 'color' => '#2f8a5f', 'done' => true],
                    ]]],
                    ['Due date', 'date', 130],
                    ['Photos', 'files', 140],
                ],
            ],
            'safety' => [
                'title' => 'Health & safety', 'type' => 'safety', 'icon' => 'shield', 'noun' => 'entry',
                'description' => 'Inspections, toolbox talks, incidents and the actions they raise.',
                'groups' => [['Inspections', '#2f5c84'], ['Incidents & near misses', '#c2412f'], ['Toolbox talks', '#2f7457']],
                'columns' => [
                    ['Type', 'dropdown', 140, ['options' => ['Inspection', 'Toolbox talk', 'Incident', 'Near miss', 'Audit']]],
                    ['Date', 'date', 120],
                    ['Responsible', 'people', 140],
                    ['Severity', 'priority', 120],
                    ['Status', 'status', 150, ['labels' => [
                        ['key' => 'open', 'label' => 'Open', 'color' => '#85766b'],
                        ['key' => 'action', 'label' => 'Action required', 'color' => '#c2412f'],
                        ['key' => 'closed', 'label' => 'Closed', 'color' => '#2f8a5f', 'done' => true],
                    ]]],
                    ['Files', 'files', 130],
                ],
            ],
            'blank' => [
                'title' => 'New board', 'type' => 'general', 'icon' => 'grid', 'noun' => 'item',
                'description' => 'Start with a status, a person and a date column.',
                'groups' => [['Group 1', '#2f5c84']],
                'columns' => [
                    ['Person', 'people', 140],
                    ['Status', 'status', 150, self::STATUS_WORK],
                    ['Date', 'date', 130],
                ],
            ],
        ];
    }

    public static function board(string $key): array
    {
        $all = self::boards();
        return $all[$key] ?? $all['blank'];
    }

    /** Project templates for the "New project" dialog. */
    public static function projects(): array
    {
        return [
            'blank' => ['label' => 'Simple project', 'hint' => 'One Tasks board — add more whenever you need them.', 'boards' => ['tasks']],
            'construction_standard' => ['label' => 'Construction project', 'hint' => 'Programme, Costing, Procurement and Snags boards.', 'boards' => ['schedule', 'costing', 'procurement', 'snags']],
            'service' => ['label' => 'Service job', 'hint' => 'Tasks plus a Costing board for materials and labour.', 'boards' => ['tasks', 'costing']],
            'empty' => ['label' => 'Empty', 'hint' => 'No boards yet.', 'boards' => []],
        ];
    }

    /** Board keys to create for a project template. @return string[] */
    public static function projectBoards(string $key): array
    {
        $all = self::projects();
        return ($all[$key] ?? $all['blank'])['boards'];
    }

    /** Summary for the browser (no column internals). */
    public static function catalogue(): array
    {
        $out = [];
        foreach (self::boards() as $k => $b) {
            $out[] = ['key' => $k, 'title' => $b['title'], 'description' => $b['description'], 'icon' => $b['icon'],
                      'columns' => array_map(fn($c) => $c[0], $b['columns'])];
        }
        $projects = [];
        foreach (self::projects() as $k => $p) $projects[] = ['key' => $k] + $p;
        return ['boards' => $out, 'projects' => $projects];
    }
}
