<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Public address shared with guests
    |--------------------------------------------------------------------------
    |
    | Every link that leaves the system goes into an SMS or a WhatsApp message
    | and is opened on somebody else's phone, so it has to be an address the
    | guest's network can reach. APP_URL is often a LAN address such as
    | http://10.0.0.5:8000 while developing, and such a link is useless to a
    | guest standing somewhere else.
    |
    | Set ECARD_PUBLIC_URL to the address guests should use, typically your
    | tunnel or deployed domain. It deliberately does not follow the host the
    | admin happens to be browsing from, because the link a guest receives must
    | not change depending on where the admin was sitting when they pressed send.
    |
    */

    'public_url' => rtrim((string) env('ECARD_PUBLIC_URL', env('APP_URL', 'http://localhost')), '/'),

    /*
    |--------------------------------------------------------------------------
    | Host names that only work on the machine they were typed on
    |--------------------------------------------------------------------------
    |
    | Used to warn the admin rather than to block anything. Private and reserved
    | IP literals such as 127.0.0.1 and 10.x are detected without help, so this
    | list only needs names that are not addresses at all.
    |
    */

    'unreachable_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ECARD_UNREACHABLE_HOSTS', 'localhost'))
    ))),

    /*
    |--------------------------------------------------------------------------
    | How long the result of a link check is kept
    |--------------------------------------------------------------------------
    |
    | The public address is never opened from a page load, because the address
    | leads back to this machine and the fetch would queue behind the request
    | asking for it. It is opened by `php artisan ecard:check-link`, and the
    | answer is kept for this long before the page stops trusting it and says
    | the link has not been checked.
    |
    */

    'public_url_probe_seconds' => (int) env('ECARD_PUBLIC_URL_PROBE_SECONDS', 900),

];
