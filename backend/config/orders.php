<?php

return [
    // Short window for local verification; live deployments default to one day.
    // Set ORDER_AUTO_COMPLETE_AFTER_MINUTES explicitly to override either value.
    'auto_complete_after_minutes' => (int) env(
        'ORDER_AUTO_COMPLETE_AFTER_MINUTES',
        config('app.env', 'production') === 'local' ? 1 : 1440
    ),
];
