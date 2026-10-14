<?php

return [
    // Use the short receipt window locally for verification; live deployments
    // give the recipient seven days to confirm a sent payout. Deployments can
    // override either value.
    'auto_complete_after_minutes' => (int) env(
        'PAYOUT_AUTO_COMPLETE_AFTER_MINUTES',
        config('app.env', 'production') === 'local' ? 1 : 10080
    ),
];
