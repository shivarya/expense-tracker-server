<?php

/**
 * CancelUrlMap
 *
 * Seeds a cancellation link for a newly-detected subscription. Small curated
 * map of common merchants' real cancel/manage-subscription pages; anything
 * unmatched falls back to a search link. Users can override the stored
 * cancel_url per subscription from the app afterward.
 */
class CancelUrlMap
{
    private const MAP = [
        'netflix' => 'https://www.netflix.com/CancelPlan',
        'spotify' => 'https://www.spotify.com/account/subscription/',
        'amazon prime' => 'https://www.amazon.in/mc/pipelines/cancellation',
        'prime video' => 'https://www.amazon.in/mc/pipelines/cancellation',
        'amazonprime' => 'https://www.amazon.in/mc/pipelines/cancellation',
        'hotstar' => 'https://www.hotstar.com/in/subscription/manage',
        'jiocinema' => 'https://www.jiocinema.com/subscriptions',
        'youtube premium' => 'https://www.youtube.com/paid_memberships',
        'youtubepremium' => 'https://www.youtube.com/paid_memberships',
        'google one' => 'https://one.google.com/settings/storage',
        'google play' => 'https://play.google.com/store/account/subscriptions',
        'googleplay' => 'https://play.google.com/store/account/subscriptions',
        'icloud' => 'https://support.apple.com/en-in/HT202039',
        'apple.com/bill' => 'https://support.apple.com/en-in/HT202039',
        'apple music' => 'https://support.apple.com/en-in/HT202039',
        'sonyliv' => 'https://www.sonyliv.com/subscribe',
        'zee5' => 'https://www.zee5.com/subscription',
        'cult.fit' => 'https://www.cult.fit/membership',
        'cultfit' => 'https://www.cult.fit/membership',
        'cure.fit' => 'https://www.cult.fit/membership',
        'curefit' => 'https://www.cult.fit/membership',
        'audible' => 'https://www.audible.in/account/membership',
        'linkedin premium' => 'https://www.linkedin.com/premium/products/',
        'notion' => 'https://www.notion.so/my-integrations',
        'github' => 'https://github.com/settings/billing/summary',
        'aws' => 'https://console.aws.amazon.com/billing/home',
        'microsoft 365' => 'https://account.microsoft.com/services/',
        'office 365' => 'https://account.microsoft.com/services/',
        'disney' => 'https://www.hotstar.com/in/subscription/manage',
        'zomato gold' => 'https://www.zomato.com/webroutes/user/edit',
        'swiggy one' => 'https://www.swiggy.com/my-account',
    ];

    public static function lookup(string $rawMerchant): string
    {
        $normalized = strtolower(trim($rawMerchant));

        foreach (self::MAP as $needle => $url) {
            if ($normalized !== '' && str_contains($normalized, $needle)) {
                return $url;
            }
        }

        $query = urlencode(trim($rawMerchant) . ' subscription cancel');
        return 'https://www.google.com/search?q=' . $query;
    }
}
