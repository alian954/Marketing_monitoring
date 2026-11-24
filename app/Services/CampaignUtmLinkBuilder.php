<?php

namespace App\Services;

use Illuminate\Support\Str;
use Illuminate\Support\Uri;

class CampaignUtmLinkBuilder
{
    public function build(
        string $destinationUrl,
        string $source,
        string $medium,
        string $campaign,
        string $content,
    ): string {
        $uri = Uri::of($destinationUrl);
        $query = $uri->query()->all();
        $utmParameterNames = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content'];

        foreach (array_keys($query) as $parameterName) {
            if (is_string($parameterName) && in_array(Str::lower($parameterName), $utmParameterNames, true)) {
                unset($query[$parameterName]);
            }
        }

        return (string) $uri
            ->replaceQuery($query)
            ->withQuery([
                'utm_source' => $source,
                'utm_medium' => $medium,
                'utm_campaign' => $campaign,
                'utm_content' => $content,
            ]);
    }
}
