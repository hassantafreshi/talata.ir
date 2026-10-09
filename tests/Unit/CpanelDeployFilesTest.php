<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The cPanel deployment files (docs/DEPLOYMENT.md §13) stay consistent and secret-free. */
class CpanelDeployFilesTest extends TestCase
{
    private function root(string $path = ''): string
    {
        return dirname(__DIR__, 2).'/'.ltrim($path, '/');
    }

    public function test_cpanel_yml_runs_the_deploy_script(): void
    {
        $yml = (string) file_get_contents($this->root('.cpanel.yml'));
        $this->assertStringContainsString('deployment:', $yml);
        $this->assertStringContainsString('scripts/deploy/cpanel-deploy.sh', $yml);
        $this->assertFileExists($this->root('scripts/deploy/cpanel-deploy.sh'));
    }

    public function test_the_deploy_script_is_valid_bash_and_touches_only_its_own_places(): void
    {
        $script = $this->root('scripts/deploy/cpanel-deploy.sh');
        exec('bash -n '.escapeshellarg($script).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $text = (string) file_get_contents($script);
        $this->assertStringContainsString('set -euo pipefail', $text);
        // The only recursive deletes are the release folder, the rotation of old releases and old hashed assets.
        preg_match_all('/^.*rm -rf.*$/m', $text, $m);
        foreach ($m[0] as $line) {
            $this->assertMatchesRegularExpression('/\$REL|\$APP\/releases/', $line, 'unexpected rm -rf: '.$line);
        }
        // Secrets never live in the repository: the .env template has empty key placeholders only.
        $this->assertMatchesRegularExpression('/^KAVENEGAR_API_KEY=$/m', $text);
        $this->assertMatchesRegularExpression('/^BRSAPI_KEY=$/m', $text);
        $this->assertDoesNotMatchRegularExpression('/(KEY|TOKEN|PASSWORD)=[A-Za-z0-9]{12,}/', $text);
    }

    public function test_ci_publishes_a_built_release_branch_and_keeps_secrets_out_of_it(): void
    {
        $ci = (string) file_get_contents($this->root('.github/workflows/ci.yml'));
        $this->assertStringContainsString('publish-cpanel-release', $ci);
        $this->assertStringContainsString('cpanel-release', $ci);
        $this->assertStringContainsString('--exclude=./.gitignore', $ci, 'the root .gitignore would hide vendor/ and public/build');
        $this->assertStringContainsString('--exclude=./.env', $ci);
        $this->assertStringNotContainsStringIgnoringCase('hostinger', $ci);
    }
}
