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

    /**
     * @return list<array{product_code: string, feature_key: string}>
     */
    public function featureRequirementsForRoute(?string $routeName): array
    {
        if ($routeName === null) {
            return [];
        }

        $requirements = [];

        foreach ($this->all() as $productCode => $manifest) {
            foreach ($manifest['route_features'] ?? [] as $requiredRouteName => $featureKeys) {
                if ($requiredRouteName !== $routeName) {
                    continue;
                }

                foreach ($featureKeys as $featureKey) {
                    $requirements[] = [
                        'product_code' => $productCode,
                        'feature_key' => $featureKey,
                    ];
                }
            }
        }

        return $requirements;
    }

    /**
     * The product that owns a scheduled Artisan command, or null for central platform maintenance commands.
     */
    public function productCodeForScheduledCommand(string $commandName): ?string
    {
        foreach ($this->all() as $productCode => $manifest) {
            if (in_array($commandName, $manifest['scheduled_commands'] ?? [], true)) {
                return $productCode;
            }
        }

        return null;
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
        $hasValidFeatureLabels = $this->hasValidFeatureLabels(
            $manifest['feature_keys'] ?? null,
            $manifest['feature_labels'] ?? null,
        );
        $hasValidRouteFeatures = $this->hasValidRouteFeatures(
            $manifest['feature_keys'] ?? null,
            $manifest['route_features'] ?? [],
        );

        if (! $isValidCode
            || ($manifest['product_code'] ?? null) !== $productCode
            || ! $hasValidName
            || ! $hasValidVersion
            || ! $hasValidManifestVersion
            || ! $hasValidLifecycle
            || ! $hasValidEntryRoute
            || ! $hasValidFeatureKeys
            || ! $hasValidFeatureLabels
            || ! $hasValidRouteFeatures
            || ! $this->hasValidScheduledCommands($productCode, $manifest['scheduled_commands'] ?? [])) {
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

    private function hasValidFeatureLabels(mixed $featureKeys, mixed $featureLabels): bool
    {
        if (! is_array($featureKeys) || ! is_array($featureLabels) || count($featureKeys) !== count($featureLabels)) {
            return false;
        }

        foreach ($featureKeys as $featureKey) {
            if (! is_string($featureKey)
                || ! is_string($featureLabels[$featureKey] ?? null)
                || trim($featureLabels[$featureKey]) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * Scheduled commands are optional, must be unique names, and may belong to only one product.
     */
    private function hasValidScheduledCommands(string $productCode, mixed $scheduledCommands): bool
    {
        if (! is_array($scheduledCommands) || ! array_is_list($scheduledCommands)) {
            return false;
        }

        if (count($scheduledCommands) !== count(array_unique($scheduledCommands, SORT_REGULAR))) {
            return false;
        }

        foreach ($scheduledCommands as $commandName) {
            if (! is_string($commandName) || preg_match('/\A[a-z0-9-]+(?::[a-z0-9-]+)+\z/', $commandName) !== 1) {
                return false;
            }

            foreach ($this->manifests as $otherProductCode => $otherManifest) {
                if ($otherProductCode !== $productCode
                    && is_array($otherManifest)
                    && in_array($commandName, $otherManifest['scheduled_commands'] ?? [], true)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function hasValidRouteFeatures(mixed $featureKeys, mixed $routeFeatures): bool
    {
        if (! is_array($featureKeys) || ! is_array($routeFeatures)) {
            return false;
        }

        foreach ($routeFeatures as $routeName => $requiredFeatureKeys) {
            if (! is_string($routeName)
                || trim($routeName) === ''
                || ! is_array($requiredFeatureKeys)
                || ! array_is_list($requiredFeatureKeys)
                || $requiredFeatureKeys === []) {
                return false;
            }

            foreach ($requiredFeatureKeys as $featureKey) {
                if (! is_string($featureKey) || ! in_array($featureKey, $featureKeys, true)) {
                    return false;
                }
            }
        }

        return true;
    }
}
