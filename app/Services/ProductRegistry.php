<?php

namespace App\Services;

use InvalidArgumentException;

class ProductRegistry
{
    /**
     * @param  array<string, array<string, mixed>>  $manifests
     */
    public function __construct(private readonly array $manifests) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        foreach ($this->manifests as $productCode => $manifest) {
            if (! is_string($productCode) || ! is_array($manifest)) {
                throw new InvalidArgumentException('Every product manifest must be an object keyed by product code.');
            }

            $this->validateManifest($productCode, $manifest);
        }

        return $this->manifests;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $productCode): ?array
    {
        $manifests = $this->all();

        return $manifests[$productCode] ?? null;
    }

    public function declaresFeature(string $productCode, string $featureKey): bool
    {
        $manifest = $this->find($productCode);

        return $manifest !== null && in_array($featureKey, $manifest['feature_keys'], true);
    }

    private function validateManifest(string $productCode, array $manifest): void
    {
        $isValidCode = preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $productCode) === 1;
        $hasValidName = is_string($manifest['name'] ?? null) && trim($manifest['name']) !== '';
        $hasValidVersion = is_string($manifest['version'] ?? null) && trim($manifest['version']) !== '';
        $hasValidManifestVersion = is_int($manifest['manifest_version'] ?? null)
            && $manifest['manifest_version'] > 0;
        $hasValidLifecycle = in_array($manifest['lifecycle'] ?? null, ['active', 'maintenance', 'retired'], true);
        $hasValidEntryRoute = is_string($manifest['entry_route'] ?? null)
            && trim($manifest['entry_route']) !== '';
        $hasValidFeatureKeys = $this->hasValidFeatureKeys($manifest['feature_keys'] ?? null);

        if (! $isValidCode
            || ($manifest['product_code'] ?? null) !== $productCode
            || ! $hasValidName
            || ! $hasValidVersion
            || ! $hasValidManifestVersion
            || ! $hasValidLifecycle
            || ! $hasValidEntryRoute
            || ! $hasValidFeatureKeys) {
            throw new InvalidArgumentException("The product manifest [{$productCode}] is invalid.");
        }
    }

    private function hasValidFeatureKeys(mixed $featureKeys): bool
    {
        if (! is_array($featureKeys) || ! array_is_list($featureKeys)) {
            return false;
        }

        $registeredFeatureKeys = [];

        foreach ($featureKeys as $featureKey) {
            if (! is_string($featureKey) || trim($featureKey) === '' || isset($registeredFeatureKeys[$featureKey])) {
                return false;
            }

            $registeredFeatureKeys[$featureKey] = true;
        }

        return true;
    }
}
