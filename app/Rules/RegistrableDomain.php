<?php

namespace App\Rules;

use App\Detection\PublicSuffixList;
use App\Support\Hostname;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * The domain you renew, not a subdomain or a public suffix (SPEC §3.6, §10.5).
 * Expects a normalised name.
 */
class RegistrableDomain implements ValidationRule
{
    /**
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $registrable = app(PublicSuffixList::class)->registrableDomain($value);

        if ($registrable === null) {
            $fail("{$value} is a public suffix, not a domain.");
        } elseif ($registrable !== $value) {
            $fail('Enter the domain you renew, '.Hostname::display($registrable).', not a subdomain.');
        }
    }
}
