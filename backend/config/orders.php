<?php

return [
    // Short window for local verification; live deployments give the buyer
    // three days to confirm receipt after the order is out for delivery.
    // Set ORDER_AUTO_COMPLETE_AFTER_MINUTES explicitly to override either value.
    'auto_complete_after_minutes' => (int) env(
        'ORDER_AUTO_COMPLETE_AFTER_MINUTES',
        config('app.env', 'production') === 'local' ? 1 : 4320
    ),
];
