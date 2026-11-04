<?php

namespace GamePlatform\Ops;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turns player feedback into issues on the game's GitHub repository
 * (services.github_issues: token, repo), with the
 * details a developer needs to follow it up. Never includes the player's email.
 */
final class GithubIssues
{
    /** Create the issue and return its URL. */
    public static function create(Feedback $feedback): string
    {
        $token = Config::get('services.github_issues.token');
        if (! is_string($token) || $token === '') {
            throw new RuntimeException('GITHUB_ISSUES_TOKEN is not set');
        }

        $bug = $feedback->type === Feedback::BUG;

        try {
            $response = Http::withToken($token)
                ->acceptJson()
                ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
                ->timeout(10)
                ->post('https://api.github.com/repos/'.Config::string('services.github_issues.repo').'/issues', [
                    'title' => ($bug ? '[Bug] ' : '[Suggestion] ').Str::limit($feedback->title, 200),
                    'body' => self::body($feedback),
                    'labels' => [$bug ? 'bug' : 'enhancement', 'from-player'],
                ])
                ->throw();
        } catch (RequestException $e) {
            throw new RuntimeException('GitHub refused the issue: '.$e->response->status(), previous: $e);
        }

        return (string) $response->json('html_url');
    }

    /** The player's words, then the details, as GitHub Markdown. */
    public static function body(Feedback $feedback): string
    {
        $meta = $feedback->meta;
        $errors = $meta['errors'] ?? [];
        unset($meta['errors']);

        $rows = collect($meta)
            ->map(fn (mixed $value, string $key): string => '| '.$key.' | '.self::cell($value).' |')
            ->implode("\n");

        $body = trim($feedback->body)."\n\n---\n\n"
            ."**Report #{$feedback->id}**"
            .($feedback->state !== null ? ' · save snapshot stored with the report' : '')
            ."\n\n| | |\n|---|---|\n".$rows;

        if (is_array($errors) && $errors !== []) {
            $body .= "\n\n<details><summary>Recent errors in the browser (".count($errors).")</summary>\n\n```\n"
                .Str::limit(implode("\n\n", array_map(strval(...), $errors)), 6000)
                ."\n```\n</details>";
        }

        return Str::limit($body, 60000);
    }

    /** One table cell: short, single-line, with Markdown's pipe escaped. */
    private static function cell(mixed $value): string
    {
        $text = is_scalar($value) || $value === null
            ? var_export($value, true)
            : (string) json_encode($value, JSON_UNESCAPED_SLASHES);
        $text = is_string($value) ? $value : $text;

        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ''], Str::limit($text, 300));
    }
}
