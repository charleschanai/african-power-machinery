<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use Illuminate\Console\Command;

/**
 * Opens the shared link address and leaves the answer for the Invitations page.
 *
 * This is the only place the address is ever fetched. The page cannot do it
 * itself, because the address a guest is given leads back to this same machine
 * through the tunnel, so a request that fetched it would be asking the server
 * to answer a second request while it is still on the first. Under
 * `php artisan serve`, which answers one request at a time, that queues and the
 * page hangs until the fetch times out. From the command line the server is
 * free, so the check is honest.
 *
 * Run it after starting or changing the tunnel, and before sending any SMS:
 *
 *   php artisan ecard:check-link
 */
class CheckPublicLink extends Command
{
    protected $signature = 'ecard:check-link';

    protected $description = 'Check that the shared card link address really serves the app, and remember the answer for the Invitations page';

    public function handle(): int
    {
        $url = Invitation::publicBaseUrl();

        $this->line('  address : '.$url);
        $this->line('  fetching, this takes a few seconds');

        $status = Invitation::probePublicUrl();

        $this->newLine();
        $this->line('  can a guest open it : '.($status['ok'] ? 'yes' : 'no'));
        $this->line('  verdict             : '.$status['level']);
        $this->line('  why                 : '.$status['reason']);
        $this->newLine();

        // Only one verdict actually costs money, and it must be the only one
        // that shouts. Treating an unfinished check as though it were a dead
        // link is how an admin ends up paying to replace a tunnel that was
        // working perfectly well, so unknown gets its own quieter line.
        if ($status['level'] === 'broken') {
            $this->components->error('Do not send any SMS yet. A guest would get an error instead of the card.');
        } elseif ($status['level'] === 'unknown') {
            $this->components->warn('Not checked all the way through, so this is not a clear yes.');
            $this->line('  Nothing says the link is broken. Open it on your own phone, on mobile');
            $this->line('  data rather than on this computer\'s wifi, and if the card shows, it works.');
        } else {
            $this->components->info('Links will open on a guest phone.');
        }

        $this->line('  The Invitations page will show this until it is checked again.');
        $this->newLine();

        return self::SUCCESS;
    }
}
