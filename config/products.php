<?php

return [
    'website-monitoring' => [
        'product_code' => 'website-monitoring',
        'name' => '網站可靠度監測',
        'version' => '0.1.0',
        'manifest_version' => 1,
        'lifecycle' => 'active',
        'entry_route' => 'monitors.index',
        'feature_keys' => [
            'monitoring.targets',
            'monitoring.check_history',
            'monitoring.incidents',
            'monitoring.maintenance_windows',
            'monitoring.status_pages',
            'monitoring.notifications',
        ],
    ],
    'marketing-insights' => [
        'product_code' => 'marketing-insights',
        'name' => '行銷成效分析',
        'version' => '0.1.0',
        'manifest_version' => 1,
        'lifecycle' => 'active',
        'entry_route' => 'clients.performance',
        'feature_keys' => [
            'marketing.social_posts',
            'marketing.analytics.ga4',
            'marketing.analytics.search_console',
            'marketing.ads.meta',
            'marketing.ads.google',
            'marketing.reports',
        ],
    ],
];
