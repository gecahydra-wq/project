<?php

return [
    // Give the recipient one day to confirm the payout before the system
    // automatically completes the withdrawal. Deployments can override this.
    'auto_complete_after_minutes' => (int) env(
        'PAYOUT_AUTO_COMPLETE_AFTER_MINUTES',
        1440
    ),
];
