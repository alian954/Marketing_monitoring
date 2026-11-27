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
        'feature_labels' => [
            'monitoring.targets' => '監測目標',
            'monitoring.check_history' => '檢查紀錄',
            'monitoring.incidents' => '事件管理',
            'monitoring.maintenance_windows' => '維護時段',
            'monitoring.status_pages' => '公開狀態頁',
            'monitoring.notifications' => '通知規則',
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
        'feature_labels' => [
            'marketing.social_posts' => '公開貼文監測',
            'marketing.analytics.ga4' => 'Google Analytics 4',
            'marketing.analytics.search_console' => 'Google Search Console',
            'marketing.ads.meta' => 'Meta 廣告數據',
            'marketing.ads.google' => 'Google Ads 數據',
            'marketing.reports' => '行銷成效報表',
        ],
    ],
];
