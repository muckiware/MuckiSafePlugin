<?php declare(strict_types=1);
/**
 * MuckiSafePlugin
 *
 * @category   SW6 Plugin
 * @package    MuckiSafe
 * @copyright  Copyright (c) 2026 by Muckiware
 * @license    MIT
 * @author     Muckiware
 *
 */
namespace MuckiSafePlugin\Setup;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Creates and removes the custom field sets defined as JSON files in
 * src/CustomFields/CustomFieldSets. Existing sets and fields are never overwritten.
 */
class CustomFieldInstaller
{
    protected EntityRepository $customFieldSetRepository;
    protected EntityRepository $customFieldRepository;
    protected string $definitionPattern;

    public function __construct(ContainerInterface $container)
    {
        $setRepository = $container->get('custom_field_set.repository');
        assert($setRepository instanceof EntityRepository);
        $this->customFieldSetRepository = $setRepository;

        $fieldRepository = $container->get('custom_field.repository');
        assert($fieldRepository instanceof EntityRepository);
        $this->customFieldRepository = $fieldRepository;

        $this->definitionPattern = dirname(__DIR__) . '/CustomFields/CustomFieldSets/*.json';
    }

    public function sync(Context $context): void
    {
        foreach ($this->loadDefinitions() as $definition) {
            $set = $this->findOrCreateSet($definition, $context);
            if ($set === null) {
                continue;
            }

            foreach ($this->getFieldDefinitions($definition) as $fieldDefinition) {
                if ($this->findField((string) $fieldDefinition['name'], $set->getId(), $context) === null) {
                    $this->createField($fieldDefinition, $set->getId(), $context);
                }
            }
        }
    }

    public function remove(Context $context): void
    {
        foreach ($this->loadDefinitions() as $definition) {
            $set = $this->findSet((string) $definition['name'], $context);
            if ($set === null) {
                continue;
            }

            foreach ($this->getFieldDefinitions($definition) as $fieldDefinition) {
                $field = $this->findField((string) $fieldDefinition['name'], $set->getId(), $context);
                if ($field !== null) {
                    $this->customFieldRepository->delete([['id' => $field->getId()]], $context);
                }
            }

            $this->customFieldSetRepository->delete([['id' => $set->getId()]], $context);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function loadDefinitions(): array
    {
        $definitions = [];

        foreach (glob($this->definitionPattern) ?: [] as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $decoded = json_decode($content, true);
            if (!is_array($decoded) || !isset($decoded['name'])) {
                continue;
            }

            $definitions[] = $decoded;
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $definition
     * @return array<int, array<string, mixed>>
     */
    protected function getFieldDefinitions(array $definition): array
    {
        /** @var array<int, array<string, mixed>> $fields */
        $fields = (array) ($definition['customFields'] ?? []);

        return $fields;
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function findOrCreateSet(array $definition, Context $context): ?CustomFieldSetEntity
    {
        $name = (string) $definition['name'];
        $set = $this->findSet($name, $context);
        if ($set !== null) {
            return $set;
        }

        $this->customFieldSetRepository->create([[
            'id' => Uuid::randomHex(),
            'name' => $name,
            'config' => $definition['config'] ?? [],
            'relations' => $definition['relations'] ?? [],
        ]], $context);

        return $this->findSet($name, $context);
    }

    protected function findSet(string $name, Context $context): ?CustomFieldSetEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));
        $criteria->setLimit(1);

        $result = $this->customFieldSetRepository->search($criteria, $context)->first();

        return $result instanceof CustomFieldSetEntity ? $result : null;
    }

    protected function findField(string $name, string $setId, Context $context): ?CustomFieldEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(
            new EqualsFilter('name', $name),
            new EqualsFilter('customFieldSetId', $setId),
        );
        $criteria->setLimit(1);

        $result = $this->customFieldRepository->search($criteria, $context)->first();

        return $result instanceof CustomFieldEntity ? $result : null;
    }

    /**
     * @param array<string, mixed> $fieldDefinition
     */
    protected function createField(array $fieldDefinition, string $setId, Context $context): void
    {
        $this->customFieldRepository->create([[
            'id' => Uuid::randomHex(),
            'name' => $fieldDefinition['name'],
            'type' => $fieldDefinition['type'],
            'config' => $fieldDefinition['config'] ?? [],
            'customFieldSetId' => $setId,
        ]], $context);
    }
}
