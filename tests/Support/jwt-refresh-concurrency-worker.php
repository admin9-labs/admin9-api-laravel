<?php

declare(strict_types=1);

use App\Support\Auth\RefreshJwtToken;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Http\Request;
use PHPOpenSourceSaver\JWTAuth\Blacklist;
use PHPOpenSourceSaver\JWTAuth\Manager;
use PHPOpenSourceSaver\JWTAuth\Payload;
use PHPOpenSourceSaver\JWTAuth\Token;

require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $application = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $application->afterBootstrapping(LoadConfiguration::class, function () use ($input): void {
        config([
            'cache.stores.file.path' => $input['directory'].'/cache',
            'cache.stores.file.lock_path' => $input['directory'].'/cache',
        ]);
    });
    $application->make(Kernel::class)->bootstrap();

    if (config('cache.default') === 'file'
        && app('cache.store')->getStore()->getDirectory() !== $input['directory'].'/cache') {
        throw new RuntimeException('Refusing to use a non-isolated file cache.');
    }

    $blacklist = new class(app('tymon.jwt.provider.storage')) extends Blacklist
    {
        public string $directory;

        public string $worker;

        private bool $reportedReady = false;

        public function has(Payload $payload): bool
        {
            $result = parent::has($payload);

            if (! $this->reportedReady) {
                touch($this->directory.'/'.$this->worker.'-ready');
                $this->reportedReady = true;
            }

            return $result;
        }

        public function add(Payload $payload): bool
        {
            if ($this->worker === 'first') {
                touch($this->directory.'/first-paused');
                $deadline = microtime(true) + 10;

                while (! is_file($this->directory.'/release')) {
                    if (microtime(true) >= $deadline) {
                        throw new RuntimeException('Refresh barrier timed out.');
                    }

                    usleep(10000);
                }
            }

            return parent::add($payload);
        }
    };
    $blacklist->directory = $input['directory'];
    $blacklist->worker = $input['worker'];
    $blacklist->setRefreshTTL((int) config('jwt.refresh_ttl'));
    $application->instance('tymon.jwt.blacklist', $blacklist);

    $request = Request::create('/refresh', 'POST', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$input['token']]);

    try {
        $refreshed = app(RefreshJwtToken::class)->handle($request, $input['guard']);
        $payload = app(Manager::class)->decode(new Token($refreshed['token']));
        $result = [
            'status' => 200,
            'subject_id' => $refreshed['subject']->getAuthIdentifier(),
            'replacement_valid' => $payload->get('guard') === $input['guard'],
            'replacement_hash' => hash('sha256', $refreshed['token']),
        ];
    } catch (AuthenticationException) {
        $result = ['status' => 401];
    }

    fwrite(STDOUT, json_encode($result, JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage());

    exit(1);
}
