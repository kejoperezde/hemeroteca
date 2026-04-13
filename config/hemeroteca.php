<?php

return [
    // When enabled, Laravel authorizes access and Apache serves the file body via X-Sendfile.
    'use_x_sendfile' => (bool) env('HEMEROTECA_USE_X_SENDFILE', false),

    // Apache default header for mod_xsendfile.
    'x_sendfile_header' => env('HEMEROTECA_X_SENDFILE_HEADER', 'X-Sendfile'),
];
