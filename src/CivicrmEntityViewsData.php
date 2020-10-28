<?php

namespace Drupal\civicrm_entity;

use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\views\EntityViewsData;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Sql\SqlEntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CivicrmEntityViewsData extends EntityViewsData {

  /**
   * The CiviCRM API.
   *
   * @var \Drupal\civicrm_entity\CiviCrmApiInterface
   */
  protected $civicrmApi;

  /**
   * {@inheritdoc}
   */
  public function __construct(EntityTypeInterface $entity_type, SqlEntityStorageInterface $storage_controller, EntityTypeManagerInterface $entity_type_manager, ModuleHandlerInterface $module_handler, TranslationInterface $translation_manager, EntityFieldManagerInterface $entity_field_manager = NULL, CiviCrmApiInterface $civicrm_api) {
    parent::__construct($entity_type, $storage_controller, $entity_type_manager, $module_handler, $translation_manager, $entity_field_manager);
    $this->civicrmApi = $civicrm_api;
    $this->civicrmApi->civicrmInitialize();
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('entity_type.manager')->getStorage($entity_type->id()),
      $container->get('entity_type.manager'),
      $container->get('module_handler'),
      $container->get('string_translation'),
      $container->get('entity_field.manager'),
      $container->get('civicrm_entity.api')
    );
  }

  public function getViewsData() {
    $data = [];
    $base_table = $this->entityType->getBaseTable() ?: $this->entityType->id();
    $base_field = $this->entityType->getKey('id');

    // Setup base information of the views data.
    $data[$base_table]['table']['group'] = sprintf('%s (CiviCRM Entity)', $this->entityType->getLabel());
    $data[$base_table]['table']['provider'] = $this->entityType->getProvider();
    $data[$base_table]['table']['entity type'] = $this->entityType->id();

    $views_base_table = $base_table;
    $data[$views_base_table]['table']['base'] = [
      'field' => $base_field,
      'title' => $this->entityType->getLabel(),
      'cache_contexts' => $this->entityType->getListCacheContexts(),
    ];
    $data[$base_table]['table']['entity revision'] = FALSE;
    if ($label_key = $this->entityType->getKey('label')) {
      $data[$views_base_table]['table']['base']['defaults'] = [
        'field' => $label_key,
      ];
    }

    // Entity types must implement a list_builder in order to use Views'
    // entity operations field.
    if ($this->entityType->hasListBuilderClass()) {
      $data[$base_table]['operations'] = [
        'field' => [
          'title' => $this->t('Operations links'),
          'help' => $this->t('Provides links to perform entity operations.'),
          'id' => 'entity_operations',
        ],
      ];
    }

    if ($this->entityType->hasViewBuilderClass()) {
      $data[$base_table]['rendered_entity'] = [
        'field' => [
          'title' => $this->t('Rendered entity'),
          'help' => $this->t('Renders an entity in a view mode.'),
          'id' => 'rendered_entity',
        ],
      ];
    }

    $this->addEntityLinks($data[$base_table]);

    // Load all typed data definitions of all fields. This should cover each of
    // the entity base, revision, data tables.
    $field_definitions = $this->entityFieldManager->getBaseFieldDefinitions($this->entityType->id());
    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $table_mapping */
    $table_mapping = $this->storage->getTableMapping();
    if ($table_mapping) {
      foreach ($field_definitions as $field_definition) {
        if ($table_mapping->allowsSharedTableStorage($field_definition->getFieldStorageDefinition())) {
          $this->mapFieldDefinition($views_base_table, $field_definition->getName(), $field_definition, $table_mapping, $data[$views_base_table]);

          // Provide a reverse relationship for the entity type that is referenced by
          // the field.
          if ($field_definition->getType() === 'entity_reference') {
            $target_entity_type_id = $field_definition->getFieldStorageDefinition()->getSetting('target_type');
            $target_entity_type = $this->entityTypeManager->getDefinition($target_entity_type_id);
            assert($target_entity_type !== NULL);
            $target_base_table = $target_entity_type->getDataTable() ?: $target_entity_type->getBaseTable();

            $field_name = $field_definition->getName();
            $pseudo_field_name = 'reverse__' . $this->entityType->id() . '__' . $field_name;
            $args = [
              '@label' => $target_entity_type->getSingularLabel(),
              '@field_name' => $field_name,
              '@entity' => $this->entityType->getLabel(),
            ];
            $data[$target_base_table][$pseudo_field_name]['relationship'] = [
              'title' => $this->t('@entity using @field_name', $args),
              'label' => $this->t('@field_name', ['@field_name' => $field_name]),
              'group' => $target_entity_type->getLabel(),
              'help' => $this->t('Relate each @entity with a @field_name set to the @label.', $args),
              'id' => 'civicrm_entity_reverse',
              'base' => $this->entityType->getDataTable() ?: $this->entityType->getBaseTable(),
              'entity_type' => $this->entityType->id(),
              'base field' => $this->entityType->getKey('id'),
              'field_name' => $field_name,
            ];
          }
        }
        else if ($table_mapping->requiresDedicatedTableStorage($field_definition->getFieldStorageDefinition())) {
          $table = $table_mapping->getDedicatedDataTableName($field_definition->getFieldStorageDefinition());

          $data[$table]['table']['group'] = $this->entityType->getLabel();
          $data[$table]['table']['provider'] = $this->entityType->getProvider();
          $data[$table]['table']['join'][$views_base_table] = [
            'left_field' => $base_field,
            'field' => 'entity_id',
            'extra' => [
              ['field' => 'deleted', 'value' => 0, 'numeric' => TRUE],
            ],
          ];
        }

        if (($field_metadata = $field_definition->getSetting('civicrm_entity_field_metadata')) && isset($field_metadata['custom_group_id'])) {
          $this->processViewsDataForCustomFields($data, $field_metadata);

          // Remove the predefined custom field property if we are able to
          // retrieve metadata for the field.
          unset($data[$base_table][$field_definition->getName()]);
        }
      }
    }

    $this->processViewsDataForSpecialFields($data, $base_table);

    return $data;
  }

  public function getViewsTableForEntityType(EntityTypeInterface $entity_type) {
    // CiviCRM Entity tables are `civicrm_*`
    return $entity_type->id();
  }

  /**
   * Provides Views integration for any datetime-based fields.
   *
   * This does not provide arguments, as that required an alter against the
   * entire Views data array, which is not possible here.
   *
   * @param string $table
   *   The table the language field is added to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   *
   * @see datetime_type_field_views_data_helper()
   */
  protected function processViewsDataForDatetime($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    // Set the 'datetime' filter type.
    $views_field['filter']['id'] = 'datetime';
    $views_field['filter']['field_name'] = $field_definition->getName();

    // Set the 'datetime' argument type.
    $views_field['argument']['id'] = 'datetime';
    $views_field['argument']['field_name'] = $field_definition->getName();

    // Set the 'datetime' sort handler.
    $views_field['sort']['id'] = 'datetime';
    $views_field['sort']['field_name'] = $field_definition->getName();
  }

  /**
   * Provides Views integration for list_string fields.
   *
   * This does not provide arguments, as that required an alter against the
   * entire Views data array, which is not possible here.
   *
   * @param string $table
   *   The table the language field is added to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   *
   * @see options_field_views_data()
   */
  protected function processViewsDataForListString($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    $views_field['filter']['id'] = 'list_field';
    $views_field['filter']['field_name'] = $field_definition->getName();
    $views_field['argument']['id'] = 'string_list_field';
    $views_field['argument']['field_name'] = $field_definition->getName();
  }

  /**
   * Provides Views integration for list_integer fields.
   *
   * This does not provide arguments, as that required an alter against the
   * entire Views data array, which is not possible here.
   *
   * @param string $table
   *   The table the language field is added to.
   * @param \Drupal\Core\Field\FieldDefinitionInterface $field_definition
   *   The field definition.
   * @param array $views_field
   *   The views field data.
   * @param string $field_column_name
   *   The field column being processed.
   *
   * @see options_field_views_data()
   */
  protected function processViewsDataForListInteger($table, FieldDefinitionInterface $field_definition, array &$views_field, $field_column_name) {
    $views_field['filter']['id'] = 'list_field';
    $views_field['filter']['field_name'] = $field_definition->getName();
    $views_field['argument']['id'] = 'number_list_field';
    $views_field['argument']['field_name'] = $field_definition->getName();
  }

  /**
   * Add views integration for custom fields.
   *
   * @param array $views_field
   *   Array of fields from ::getViewsData().
   * @param array $field_metadata
   *   An array of field metadata.
   */
  protected function processViewsDataForCustomFields(array &$views_field, array $field_metadata) {
    $field_metadata = [
      'pseudoconstant' => $field_metadata['option_group_id'] ?? NULL,
      'entity_type' => SupportedEntities::getEntityType($field_metadata['extends']),
      'name' => "custom_{$field_metadata['id']}",
    ] + $field_metadata;

    $views_field[$field_metadata['table_name']]['table'] = [
      'group' => $this->t('CiviCRM custom: @title', ['@title' => $field_metadata['title']]),
      'entity type' => $field_metadata['entity_type'],
      'entity revision' => FALSE,
      // Add automatic relationships so that custom fields from CiviCRM entities
      // are included when they are the base tables.
      'join' => [
        $field_metadata['entity_type'] => [
          'left_field' => 'id',
          'field' => 'entity_id',
        ],
      ],
    ];

    $views_field[$field_metadata['table_name']][$field_metadata['column_name']] = [
      'title' => $field_metadata['label'],
      'help' => $this->t('@message', ['@message' => empty($field_metadata['help_post']) ? 'Custom data field.' : $field_metadata['help_post']]),
      'field' => $this->getViewsFieldPlugin($field_metadata),
      'argument' => $this->getViewsArgumentPlugin($field_metadata),
      'filter' => $this->getViewsFilterPlugin($field_metadata),
      'sort' => $this->getViewsSortPlugin($field_metadata),
      'relationship' => $this->getViewsRelationshipPlugin($field_metadata),
      'entity field' => $field_metadata['name'],
    ];
  }

  /**
   * Add views integration for fields that require special handling.
   *
   * @param array $views_field
   *   Array of fields from ::getViewsData().
   * @param string $base_table
   *   The base table, most likely the CiviCRM entity type.
   */
  protected function processViewsDataForSpecialFields(array &$views_field, $base_table) {
    switch ($base_table) {
      case 'civicrm_activity':
        $views_field[$base_table]['source_contact_id']['filter'] = [
          'id' => 'civicrm_entity_civicrm_activity_contact_record',
        ];

        $views_field[$base_table]['assignee_id']['filter'] = [
          'id' => 'civicrm_entity_civicrm_activity_contact_record',
        ];

        $views_field[$base_table]['target_id']['filter'] = [
          'id' => 'civicrm_entity_civicrm_activity_contact_record',
        ];

        $views_field[$base_table]['contact'] = [
          'title' => $this->t('Contact'),
          'help' => $this->t('Relate CiviCRM contact to CiviCRM activity.'),
          'relationship' => [
            'id' => 'civicrm_entity_activity_contact',
            'base' => 'civicrm_contact',
            'base field' => 'id',
            'first field' => 'activity_id',
            'second field' => 'contact_id',
            'label' => $this->t('Contact'),
          ],
        ];

        $views_field['civicrm_contact']['activity'] = [
          'title' => $this->t('Activity'),
          'help' => $this->t('Relate CiviCRM activity to CiviCRM contact.'),
          'relationship' => [
            'id' => 'civicrm_entity_activity_contact',
            'base' => 'civicrm_activity',
            'base field' => 'id',
            'first field' => 'contact_id',
            'second field' => 'activity_id',
            'label' => $this->t('Activity'),
          ],
        ];

        unset(
          $views_field['civicrm_contact']['reverse__civicrm_activity__assignee_id'],
          $views_field['civicrm_contact']['reverse__civicrm_activity__target_id'],
          $views_field['civicrm_contact']['reverse__civicrm_activity__source_contact_id'],
          $views_field[$base_table]['assignee_id']['relationship'],
          $views_field[$base_table]['target_id']['relationship'],
          $views_field[$base_table]['source_contact_id']['relationship']
        );

        break;

      case 'civicrm_contact':
        $views_field['civicrm_contact']['user'] = [
          'title' => $this->t('User related to the CiviCRM contact'),
          'help' => $this->t('Relate user to the CiviCRM contact.'),
          'relationship' => [
            'base' => 'users_field_data',
            'base field' => 'uid',
            'first field' => 'contact_id',
            'second field' => 'uf_id',
            'id' => 'civicrm_entity_civicrm_contact_user',
            'label' => $this->t('User'),
          ],
        ];

        $views_field['users_field_data']['civicrm_contact'] = [
          'title' => $this->t('CiviCRM contact related to the user'),
          'help' => $this->t('Relate CiviCRM contact to the user.'),
          'relationship' => [
            'base' => 'civicrm_contact',
            'base field' => 'id',
            'first field' => 'uf_id',
            'second field' => 'contact_id',
            'id' => 'civicrm_entity_civicrm_contact_user',
            'label' => $this->t('CiviCRM contact'),
          ],
        ];

        break;

      case 'civicrm_phone':
        if (isset($views_field['civicrm_contact']['reverse__civicrm_phone__contact_id']['relationship'])) {
          $views_field['civicrm_contact']['reverse__civicrm_phone__contact_id']['relationship']['id'] = 'civicrm_entity_reverse_location';
          $views_field['civicrm_contact']['reverse__civicrm_phone__contact_id']['relationship']['label'] = $this->t('Phone');
        }

        break;

      case 'civicrm_address':
        if (isset($views_field['civicrm_contact']['reverse__civicrm_address__contact_id']['relationship'])) {
          $views_field['civicrm_contact']['reverse__civicrm_address__contact_id']['relationship']['id'] = 'civicrm_entity_reverse_location';
          $views_field['civicrm_contact']['reverse__civicrm_address__contact_id']['relationship']['label'] = $this->t('Address');
        }

        $views_field[$base_table]['proximity'] = [
          'title' => $this->t('Proximity'),
          'help' => $this->t('Search for addresses by proxmity.'),
          'filter' => ['id' => 'civicrm_entity_civicrm_address_proximity'],
        ];

        break;

      case 'civicrm_email':
        if (isset($views_field['civicrm_contact']['reverse__civicrm_email__contact_id']['relationship'])) {
          $views_field['civicrm_contact']['reverse__civicrm_email__contact_id']['relationship']['id'] = 'civicrm_entity_reverse_location';
          $views_field['civicrm_contact']['reverse__civicrm_email__contact_id']['relationship']['label'] = $this->t('Email');
        }

        break;

      case 'civicrm_group':
        $views_field['civicrm_group']['civicrm_contact'] = [
          'title' => $this->t('CiviCRM contact related to the CiviCRM group'),
          'help' => $this->t('Relate CiviCRM contact to the CiviCRM group.'),
          'relationship' => [
            'base' => 'civicrm_contact',
            'base field' => 'id',
            'first field' => 'group_id',
            'second field' => 'contact_id',
            'id' => 'civicrm_entity_civicrm_group_contact',
            'label' => $this->t('CiviCRM contact'),
          ],
        ];

        $views_field['civicrm_contact']['civicrm_group'] = [
          'title' => $this->t('CiviCRM group related to the CiviCRM contact'),
          'help' => $this->t('Relate CiviCRM group to the CiviCRM contact.'),
          'relationship' => [
            'base' => 'civicrm_group',
            'base field' => 'id',
            'first field' => 'contact_id',
            'second field' => 'group_id',
            'id' => 'civicrm_entity_civicrm_group_contact',
            'label' => $this->t('CiviCRM group'),
          ],
        ];

        break;

      case 'civicrm_case':
        $views_field['civicrm_case']['civicrm_activity'] = [
          'title' => $this->t('CiviCRM activity related to the CiviCRM case'),
          'help' => $this->t('Relate CiviCRM activity to the CiviCRM case.'),
          'relationship' => [
            'base' => 'civicrm_activity',
            'base field' => 'id',
            'table' => 'civicrm_case_activity',
            'first field' => 'case_id',
            'second field' => 'activity_id',
            'id' => 'civicrm_entity_civicrm_case_activity',
            'label' => $this->t('CiviCRM activity'),
          ],
        ];

        $views_field['civicrm_activity']['civicrm_case'] = [
          'title' => $this->t('CiviCRM case related to the CiviCRM activity'),
          'help' => $this->t('Relate CiviCRM case to the CiviCRM activity.'),
          'relationship' => [
            'base' => 'civicrm_case',
            'base field' => 'id',
            'table' => 'civicrm_case_activity',
            'first field' => 'activity_id',
            'second field' => 'case_id',
            'id' => 'civicrm_entity_civicrm_case_activity',
            'label' => $this->t('CiviCRM case'),
          ],
        ];

        break;
    }
  }

  /**
   * Get the views field handler.
   *
   * @param array $field_metadata
   *   An array of field metadata.
   *
   * @return array
   *   An array containing the corresponding values for the 'field' key.
   */
  protected function getViewsFieldPlugin(array $field_metadata) {
    switch ($field_metadata['data_type']) {
      case 'File':
        return ['id' => 'civicrm_entity_custom_file'];
    }

    return ['id' => 'civicrm_entity_custom_field'];
  }

  /**
   * Get the views filter handler.
   *
   * @param array $field_metadata
   *   An array of field metadata.
   *
   * @return array
   *   An array containing the corresponding values for the 'sort' key.
   */
  protected function getViewsSortPlugin(array $field_metadata) {
    $type = \CRM_Utils_Array::value($field_metadata['data_type'], \CRM_Core_BAO_CustomField::dataToType());

    switch ($type) {
      case \CRM_Utils_Type::T_DATE:
      case \CRM_Utils_Type::T_TIMESTAMP:
        return ['id' => 'date'];

      default:
        return ['id' => 'standard'];
    }
  }

  /**
   * Get the views sort handler.
   *
   * @param array $field_metadata
   *   An array of field metadata.
   *
   * @return array
   *   An array containing the corresponding values for the 'filter' key.
   */
  protected function getViewsFilterPlugin(array $field_metadata) {
    switch ($field_metadata['data_type']) {
      case 'Country':
        $filter = [
          'id' => 'civicrm_entity_in_operator',
          'options callback' => 'CRM_Core_PseudoConstant::country',
        ];

        if ($field_metadata['html_type'] === 'Multi-Select Country') {
          $filter['multi'] = TRUE;
        }

        return $filter;

      case 'StateProvince':
        $filter = [
          'id' => 'civicrm_entity_in_operator',
          'options callback' => 'CRM_Core_PseudoConstant::stateProvince',
        ];

        if ($field_metadata['html_type'] === 'Multi-Select State/Province') {
          $filter['multi'] = TRUE;
        }

        return $filter;

      case 'ContactReference':
        return ['id' => 'civicrm_entity_contact_reference'];
    }

    $type = !empty($field_metadata['pseudoconstant']) ? 'pseudoconstant' :
      \CRM_Utils_Array::value($field_metadata['data_type'], \CRM_Core_BAO_CustomField::dataToType());

    switch ($type) {
      case \CRM_Utils_Type::T_BOOLEAN:
        return ['id' => 'boolean', 'accept_null' => TRUE];

      case \CRM_Utils_Type::T_INT:
      case \CRM_Utils_Type::T_FLOAT:
      case \CRM_Utils_Type::T_MONEY:
        return ['id' => 'numeric'];

      case \CRM_Utils_Type::T_ENUM:
      case \CRM_Utils_Type::T_STRING:
      case \CRM_Utils_Type::T_TEXT:
      case \CRM_Utils_Type::T_LONGTEXT:
      case \CRM_Utils_Type::T_URL:
      case \CRM_Utils_Type::T_EMAIL:
        return ['id' => 'string'];

      case \CRM_Utils_Type::T_DATE:
      case \CRM_Utils_Type::T_TIMESTAMP:
        return ['id' => 'civicrm_entity_date'];

      case 'pseudoconstant':
        if ($class_name = SupportedEntities::getEntityTypeDaoClass($field_metadata['entity_type'])) {
          $filter = [
            'id' => 'civicrm_entity_in_operator',
            'options callback' => "{$class_name}::buildOptions",
            'options arguments' => $field_metadata['name'],
          ];

          if (in_array($field_metadata['html_type'], ['Multi-Select', 'CheckBox'])) {
            $filter['multi'] = TRUE;
          }

          return $filter;
        }
        break;

      default:
        return ['id' => 'standard'];
    }
  }

  /**
   * Get the views argument handler.
   *
   * @param array $field_metadata
   *   An array of field metadata.
   *
   * @return array
   *   An array containing the corresponding values for the 'argument' key.
   */
  protected function getViewsArgumentPlugin(array $field_metadata) {
    $type = \CRM_Utils_Array::value($field_metadata['data_type'], \CRM_Core_BAO_CustomField::dataToType());

    switch ($type) {
      case \CRM_Utils_Type::T_INT:
      case \CRM_Utils_Type::T_FLOAT:
      case \CRM_Utils_Type::T_MONEY:
        return ['id' => 'numeric'];

      case \CRM_Utils_Type::T_ENUM:
      case \CRM_Utils_Type::T_STRING:
      case \CRM_Utils_Type::T_TEXT:
      case \CRM_Utils_Type::T_LONGTEXT:
      case \CRM_Utils_Type::T_URL:
      case \CRM_Utils_Type::T_EMAIL:
        return ['id' => 'string'];

      case \CRM_Utils_Type::T_DATE:
      case \CRM_Utils_Type::T_TIMESTAMP:
        return ['id' => 'civicrm_entity_date'];

      default:
        return ['id' => 'standard'];
    }
  }

  /**
   * Get the views argument handler.
   *
   * @param array $field_metadata
   *   An array of field metadata.
   *
   * @return array
   *   An array containing the corresponding values for the 'argument' key.
   */
  protected function getViewsRelationshipPlugin(array $field_metadata) {
    switch ($field_metadata['data_type']) {
      case 'ContactReference':
        return [
          'id' => 'standard',
          'base' => 'civicrm_contact',
          'base field' => 'id',
          'label' => $this->t('@label', ['@label' => $field_metadata['label']]),
        ];

      default:
        return [];
    }
  }

}
