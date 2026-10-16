<?php

return [
    // Short window for local verification; live deployments give the buyer
    // five days to confirm receipt after the order is out for delivery.
    // Set ORDER_AUTO_COMPLETE_AFTER_MINUTES explicitly to override either value.
    'auto_complete_after_minutes' => (int) env(
        'ORDER_AUTO_COMPLETE_AFTER_MINUTES',
        config('app.env', 'production') === 'local' ? 1 : 7200
    ),

    // How long after an order is completed the buyer may still request a
    // refund (see App\Support\RefundRequests). Eligibility is derived from
    // orders.completed_at plus this number -- nothing about a request or an
    // order stores the deadline, so changing this value moves every window.
    'refund_request_window_days' => (int) env('ORDER_REFUND_WINDOW_DAYS', 7),
];
