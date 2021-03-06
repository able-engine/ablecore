<?php

namespace AbleCore;

use AbleCore\Fields\FieldValueTypes\EntityReferenceFieldValue;

class Entity extends DrupalExtension {

	/**
	 * The entity type.
	 *
	 * @var string
	 */
	protected $type;

	/**
	 * Whether the entity is new or not.
	 *
	 * @var bool
	 */
	protected $is_new = false;

	/**
	 * Whether the full entity has been loaded.
	 *
	 * @var bool
	 */
	protected $full_loaded = false;

	public function __construct($type, $definition, $full_loaded = false)
	{
		$this->base = $definition;
		$this->type = $type;
		$this->full_loaded = $full_loaded;
	}

	/**
	 * Promotes an entity to the current subclass (Node, User, etc). This allows
	 * the use of new subclasses.
	 *
	 * TODO: Remove this when #37 is fixed, as it negates the need for this function.
	 *
	 * @param Entity|EntityReferenceFieldValue $entity The current entity object (or the field value).
	 *
	 * @return static|bool The new entity object, promoted to the calling class or false if the
	 *                     entity does not exist.
	 */
	public static function promote($entity)
	{
		if ($entity instanceof EntityReferenceFieldValue) {
			$entity = $entity->raw_entity;
		}
		if (is_object($entity)) {
			return new static($entity->type(), $entity->base, $entity->full_loaded);
		} else {
			return false;
		}
	}

	/**
	 * Load (with type)
	 *
	 * Loads basic entity information from the database.
	 *
	 * @param string $entity_type The type of entity to load.
	 * @param int    $entity_id   The ID of the entity.
	 *
	 * @return static The loaded entity on success, or false on failure.
	 * @throws \Exception
	 */
	public static function loadWithType($entity_type, $entity_id)
	{
		$info = static::getEntityInfo($entity_type);

		$query = db_select($info['base table'], 'entity')
			->fields('entity')
			->condition($info['entity keys']['id'], $entity_id)
			->range(0, 1);
		$result = $query->execute()->fetch();

		return static::loadResultWithType($entity_type, $result, $entity_id);
	}

	/**
	 * Given an array of entity IDs, loads all of them and returns an array of AbleCore
	 * entity objects.
	 *
	 * @param string $entity_type The type of entity to load.
	 * @param array  $entity_ids  An array of entity IDs to load.
	 *
	 * @return array An array of loaded AbleCore entities.
	 */
	public static function loadMultipleWithType($entity_type, array $entity_ids)
	{
		// If no entity IDs were passed, just return an empty array.
		if (count($entity_ids) <= 0) {
			return array();
		}

		$info = static::getEntityInfo($entity_type);

		$query = db_select($info['base table'], 'entity')
			->fields('entity')
			->condition($info['entity keys']['id'], $entity_ids, 'in');
		$results = $query->execute();

		// Get the flipped array to preserve order.
		$flipped = array_flip($entity_ids);

		$new_entity_results = array();
		foreach ($results as $result) {
			$id = $result->{$info['entity keys']['id']};
			$new_entity_results[$flipped[$id]] = $result;
		}
		ksort($new_entity_results);

		$entities = array();
		foreach ($new_entity_results as $result) {
			$id = $result->{$info['entity keys']['id']};
			$entities[$id] = static::loadResultWithType($entity_type, $result, $id);
		}

		return $entities;
	}

	/**
	 * Internal function. Given a result object from the database, loads that result and
	 * returns the loaded entity. This handles edge cases like taxonomy terms, where the
	 * bundle isn't included in the base table in the database.
	 *
	 * @param $entity_type
	 * @param $result
	 * @param $entity_id
	 *
	 * @return bool|static
	 * @throws \Exception
	 */
	protected static function loadResultWithType($entity_type, $result, $entity_id)
	{
		$info = static::getEntityInfo($entity_type);

		if ($result) {

			// We need to make sure the bundle key is there, otherwise we need to load
			// the entire entity. This is especially true with Taxonomy Terms, where
			// the bundle is automatically generated by hooks.
			if (empty($result->{$info['entity keys']['bundle']})) {
				$result = entity_load_single($entity_type, $entity_id);
			}

			return new static($entity_type, $result);

		} else {
			return false;
		}
	}

	protected static function getEntityInfo($entity_type)
	{
		$info = entity_get_info($entity_type);
		if (!$info || empty($info['base table'])) {
			throw new \Exception('The entity type ' . $entity_type . ' is invalid.');
		}

		return $info;
	}

	/**
	 * Load by UUID (with type)
	 *
	 * @param string $entity_type The type of entity to load.
	 * @param string $entity_uuid The UUID of the entity to load.
	 *
	 * @return static The loaded entity on success, else false.
	 */
	public static function loadWithTypeByUUID($entity_type, $entity_uuid)
	{
		if (!module_exists('uuid')) return false;
		$entities = entity_get_id_by_uuid($entity_type, array($entity_uuid));
		foreach ($entities as $entity_id) {
			return static::loadWithType($entity_type, $entity_id);
		}

		return false;
	}

	/**
	 * Determines whether or not an entity exists with the specified type.
	 *
	 * @param string $entity_type The type of entity.
	 * @param mixed  $identifier  Either the ID or UUID of the entity.
	 *
	 * @return bool
	 */
	public static function existsWithType($entity_type, $identifier)
	{
		$id_type = is_numeric($identifier) || !module_exists('uuid') ? 'id' : 'uuid';
		$info = entity_get_info($entity_type);
		if (!$info) return false;

		$count = db_select($info['base table'], 'e')
			->condition($info['entity keys'][$id_type], $identifier)
			->countQuery()
			->execute()
			->fetchField();
		return $count > 0;
	}

	/**
	 * Current (with type)
	 *
	 * Gets the entity representing the current page.
	 *
	 * @param string $entity_type The entity type to load. Defaults to 'node'.
	 * @param int    $position    The position in the path where the ID for the entity lies.
	 *                            For example, for 'node/1', this value would be '1'.
	 *                            Defaults to 1.
	 *
	 * @return static The loaded entity or false on error.
	 */
	public static function currentWithType($entity_type = 'node', $position = 1)
	{
		$item = menu_get_object($entity_type, $position);
		if ($item) {
			return new static($entity_type, $item);
		} else {
			return false;
		}
	}

	/**
	 * Import
	 *
	 * Finds the ID of the entity and the type of it, and returns a new Entity object.
	 *
	 * @param object $existing_entity The existing entity.
	 *
	 * @return static Either the loaded entity, or false on failure.
	 */
	public static function import($existing_entity)
	{
		$entity_types = entity_get_info();
		foreach ($entity_types as $entity_type => $config) {
			if (array_key_exists('entity keys', $config) && array_key_exists('id', $config['entity keys'])) {
				if (isset($existing_entity->{$config['entity keys']['id']})) {
					$loaded_entity = static::loadWithType($entity_type, $existing_entity->{$config['entity keys']['id']});
					if (!$loaded_entity) continue;
					if (module_exists('uuid')) {
						$uuid_a = $existing_entity->{$config['entity keys']['uuid']};
						$uuid_b = $loaded_entity->uuid();
						if ($loaded_entity && $uuid_a == $uuid_b) {
							return $loaded_entity;
						}
					} else {
						return $loaded_entity;
					}
				}
			}
		}
		return false;
	}

	/**
	 * Import a fully loaded entity with the specified type.
	 *
	 * @param string $entity_type The entity type being imported.
	 * @param mixed  $definition  The fully-loaded definition of the entity.
	 *
	 * @return static
	 */
	public static function importFullWithType($entity_type, $definition)
	{
		return new static($entity_type, $definition, true);
	}

	/**
	 * Map (with type)
	 *
	 * Given an entity type and an array of entity IDs, returns an array
	 * of those loaded entities.
	 *
	 * @param string $entity_type The type of entity to load.
	 * @param array $entity_ids An ID of entity IDs to load.
	 *
	 * @return array The loaded entities.
	 */
	public static function mapWithType($entity_type, array $entity_ids = array())
	{
		$entities = array();
		foreach ($entity_ids as $id) {
			$entities[] = static::loadWithType($entity_type, $id);
		}

		return $entities;
	}

	/**
	 * Map Query (with type)
	 *
	 * Given a select query, executes the query and returns an array of Entity
	 * objects representing the result.
	 *
	 * @param string                $entity_type The type of entity the results represent.
	 * @param \SelectQueryInterface $query       The query.
	 * @param int                   $index       Passed to fetchCol(), represents the column to fetch.
	 *
	 * @return array An array of Entity objects, keyed by their IDs.
	 */
	public static function mapQueryWithType($entity_type, \SelectQueryInterface $query, $index = 0)
	{
		return static::loadMultipleWithType($entity_type, $query->execute()->fetchCol($index));
	}

	/**
	 * Given an Entity Field Query, will take the results and map them to AbleCore entities.
	 * If the results set only contains one entity type (or there is one specified with the
	 * $entity_type parameter), the function will return a single array of entities for that
	 * entity type. If there are multiple entity types in the result set, it will return an
	 * array of entity types, which contain arrays of their respective loaded entities.
	 *
	 * If $entity_type is provided and it does not exist in the query results, the function
	 * returns an empty array. If there are no results from the Entity Field Query, the function
	 * returns an empty array.
	 *
	 * @param \EntityFieldQuery $query        The EntityFieldQuery to map.
	 * @param mixed             $entity_type  The entity type. If false is provided, guesses the entity
	 *                                        type from the query.
	 *
	 * @return array|bool
	 */
	public static function mapEntityFieldQuery(\EntityFieldQuery $query, $entity_type = false)
	{
		$entities = $query->execute();
		if ($entity_type !== false && array_key_exists($entity_type, $entities)) {
			$entities = array($entity_type => $entities[$entity_type]);
		} elseif ($entity_type !== false && !array_key_exists($entity_type, $entities)) {
			return false;
		}

		if (count($entities) === 1) {
			return static::loadMultipleWithType(key($entities), array_keys(reset($entities)));
		} elseif (count($entities) > 1) {
			$results = array();
			foreach ($entities as $entity_type => $child_entities) {
				$results[$entity_type] = static::loadMultipleWithType($entity_type, array_keys($child_entities));
			}
			return $results;
		} else {
			return false;
		}
	}

	/**
	 * Get Latest Revision ID (with type)
	 *
	 * Gets the latest revision ID for the specified entity from the database.
	 *
	 * @param string $entity_type The entity type.
	 * @param int    $entity_id   The ID for the entity to check.
	 * @param bool   $reset       Whether or not to reset the cached results.
	 *
	 * @return int|bool The revision ID on success, false on error.
	 */
	public static function getLatestRevisionIDWithType($entity_type, $entity_id, $reset = false)
	{
		$ids = &drupal_static(__FUNCTION__, null, $reset);
		if (!isset($ids[$entity_type][$entity_id])) {

			if (!self::typeSupportsRevisions($entity_type)) {
				return $ids[$entity_type][$entity_id] = false;
			}

			$entity_info = entity_get_info($entity_type);

			$query = db_select($entity_info['revision table'], 'revision')
				->condition($entity_info['entity keys']['id'], $entity_id)
				->orderBy($entity_info['entity keys']['revision'], 'DESC')
				->range(0, 1);
			$revision_field = $query->addField('revision', $entity_info['entity keys']['revision']);
			$results = $query->execute()->fetchAll();
			if (count($results) <= 0) {
				return $ids[$entity_type][$entity_id] = false;
			} else {
				return $ids[$entity_type][$entity_id] = $results[0]->$revision_field;
			}

		}
		return $ids[$entity_type][$entity_id];
	}

	/**
	 * Type Supports Revisions
	 *
	 * Determines if the specified entity type supports revisions.
	 *
	 * @param string $entity_type The type of entity.
	 *
	 * @return bool
	 */
	public static function typeSupportsRevisions($entity_type)
	{
		$info = entity_get_info($entity_type);
		if (!array_key_exists('revision table', $info)) return false;
		if (!array_key_exists('revision', $info['entity keys'])) return false;
		if (module_exists('uuid') && !array_key_exists('revision uuid', $info['entity keys'])) return false;

		return true;
	}

	/**
	 * Delete (with type)
	 *
	 * Deletes an entity.
	 *
	 * @param string $entity_type The type of entity to delete.
	 * @param int $entity_id The ID of the entity to delete.
	 *
	 * @return bool The results of entity_delete()
	 * @see entity_delete()
	 */
	public static function deleteWithType($entity_type, $entity_id)
	{
		return entity_delete($entity_type, $entity_id);
	}

	public function __get($name)
	{
		$result = $this->field($name);
		if ($result === false) {
			try {
				return parent::__get($name);
			} catch (\Exception $ex) {
				if ($this->full_loaded) {
					return false;
				} else {
					// If we don't already have the full entity object, load it and try to get the field again.
					$this->loadFull();
					return $this->__get($name);
				}
			}
		} else {
			return $result;
		}
	}

	/**
	 * Gets the type of entity.
	 *
	 * @return string
	 */
	public function type()
	{
		return $this->type;
	}

	/**
	 * ID
	 *
	 * Gets or sets the identifier for the entity.
	 *
	 * @param mixed $value If this value is not false, updates the ID of the entity.
	 *
	 * @return bool|mixed The ID of the entity, or false if it couldn't be found.
	 */
	public function id($value = false)
	{
		return $this->key('id', $value);
	}

	/**
	 * Bundle
	 *
	 * Gets or sets the bundle for the entity.
	 *
	 * @param mixed $value If this value is not false, updates the bundle of the entity.
	 *
	 * @return bool|mixed The bundle of the entity, or false if it couldn't be found.
	 */
	public function bundle($value = false)
	{
		return $this->key('bundle', $value);
	}

	/**
	 * UUID
	 *
	 * Gets or sets the uuid for the entity.
	 *
	 * @param mixed $value If this value is not false, updates the uuid of the entity.
	 *
	 * @return bool|mixed The uuid of the entity, or false if it couldn't be found.
	 */
	public function uuid($value = false)
	{
		return $this->key('uuid', $value);
	}

	/**
	 * Revision
	 *
	 * Gets or sets the revision ID for the entity.
	 *
	 * @param mixed $value If this value is not false, updates the revision ID of the entity.
	 *
	 * @return bool|mixed The revision ID of the entity, or false if it couldn't be found.
	 */
	public function revision($value = false)
	{
		return $this->key('revision', $value);
	}

	/**
	 * Is New
	 *
	 * Gets or sets whether or not the loaded entity should be marked as new.
	 *
	 * @param bool $value If this value is not null, updates the is new flag on the entity.
	 *
	 * @return bool The is new flag of the entity.
	 */
	public function isNew($value = null)
	{
		if ($value !== null) {
			$this->is_new = $value;
		}
		return $this->is_new;
	}

	/**
	 * VUUID
	 *
	 * Gets or sets the revision UUID of the entity.
	 *
	 * @param mixed $value If this value is not false, updates the revision UUID of the entity.
	 *
	 * @return bool|mixed|string A string identifying the revision for the entity.
	 */
	public function vuuid($value = false)
	{
		$vuuid = $this->key('revision uuid', $value);
		if ($vuuid !== false) {
			return $vuuid;
		} elseif (isset($this->changed)) {
			return 'norevision|' . $this->uuid() . '|' . $this->changed;
		} elseif (module_exists('entity_modified') && function_exists('entity_modified_last') && ($modified = entity_modified_last($this->type, $this->base))) {
			return 'norevision|' . $this->uuid() . '|' . $modified;
		} else {
			return 'norevision|' . $this->uuid();
		}
	}

	/**
	 * Language
	 *
	 * Gets or sets the language for the loaded entity.
	 *
	 * @param mixed $value If this isn't false, updates the language of the entity.
	 *
	 * @return bool|mixed The language of the loaded entity, or false if it doesn't exist.
	 */
	public function language($value = false)
	{
		return $this->key('language', $value);
	}

	/**
	 * Field Language
	 *
	 * Gets the language of a specific field on the current entity.
	 *
	 * @param string $field The name of the field to get the language for.
	 *
	 * @return string|bool A language code for the current field, or false if it doesn't exist.
	 */
	public function fieldLanguage($field)
	{
		return field_language($this->type(), $this->base, $field);
	}

	/**
	 * Supports Revisions
	 *
	 * @return bool True or false depending on if the current entity supports revisions.
	 */
	public function supportsRevisions()
	{
		return self::typeSupportsRevisions($this->type());
	}

	/**
	 * Set Revision
	 *
	 * Updates the revision of the specified entity.
	 *
	 * @param mixed $revision Either the revision ID, or the revision UUID.
	 *
	 * @return bool
	 * @throws \Exception
	 */
	public function setRevision($revision)
	{
		$info = entity_get_info($this->type());
		if (!self::typeSupportsRevisions($this->type())) {
			throw new \Exception('The entity type ' . $this->type() . ' does not support revisions.');
		}

		$query = db_select($info['revision table'], 'entity')
			->fields('entity')
			->condition($info['entity keys']['id'], $this->id())
			->range(0, 1);

		if (is_numeric($revision)) {
			$query->condition($info['entity keys']['revision'], $revision);
		} elseif (module_exists('uuid')) {
			$query->condition($info['entity keys']['revision uuid'], $revision);
		} else {
			throw new \Exception('An invalid revision ID was given.');
		}

		$result = $query->execute()->fetch();

		if ($result) {

			foreach ($result as $key => $value) {
				$this->base->$key = $value;
			}

			$this->resetFields();

			return true;
		} else {
			return false;
		}
	}

	/**
	 * Path
	 *
	 * Gets the path to the current entity.
	 *
	 * @return bool|string False if there was an error, else the path to the entity.
	 */
	public function path()
	{
		$url_params = entity_uri($this->type, $this->base);
		if (is_array($url_params) && array_key_exists('path', $url_params) && array_key_exists('options', $url_params))
			return url($url_params['path'], $url_params['options']);
		else return false;
	}

	/**
	 * Alias
	 *
	 * Gets the path alias for the loaded entity (backwards compatibility).
	 *
	 * @return bool|mixed|null The path alias.
	 */
	public function alias()
	{
		return $this->path();
	}

	/**
	 * Link
	 *
	 * Generates a link to the current entity.
	 *
	 * @param string $text    The text to use for the link. If an empty string is passed,
	 *                        the label for the entity is used instead.
	 * @param array  $options Any additional options to pass to l().
	 *
	 * @return bool|string Either the HTML link, or false if an error occurred.
	 * @see l()
	 */
	public function link($text = '', array $options = array())
	{
		if (!$text) {
			$text = entity_label($this->type(), $this->base);
		}

		$url_params = entity_uri($this->type, $this->base);
		if (is_array($url_params) && array_key_exists('path', $url_params) && array_key_exists('options', $url_params)) {
			return l($text, $url_params['path'], array_merge($url_params['options'], $options));
		} else return false;
	}

	/**
	 * Save
	 *
	 * Saves the entity.
	 *
	 * @return bool The results of entity_save
	 * @see entity_save()
	 */
	public function save()
	{
		$this->is_new = false;
		return entity_save($this->type, $this->base);
	}

	/**
	 * Delete
	 *
	 * Deletes the entity.
	 *
	 * @return bool The results of entity_delete.
	 * @see entity_delete()
	 */
	public function deleteCurrent()
	{
		return self::deleteWithType($this->type, $this->id());
	}

	/**
	 * Determines whether or not the specified field exists on the node.
	 * This is different than using the __get magic method because this
	 * function only looks for fields (loaded through the field API) and
	 * it doesn't throw a warning if the field doesn't exist.
	 *
	 * @param string $field_name The field to attempt to load.
	 *
	 * @return bool Whether or not the field exists.
	 */
	public function fieldExists($field_name)
	{
		return $this->field($field_name) !== false;
	}

	/**
	 * Key
	 *
	 * Internal function. Used to get or set an entity key on the loaded entity.
	 *
	 * @param string $key   The key to get or set.
	 * @param bool   $value If this is not set to false, updates the value of the key.
	 *
	 * @return bool|mixed The value of the key if it exists, else false.
	 */
	protected function key($key, $value = false)
	{
		$entity_info = entity_get_info($this->type);
		if (isset($entity_info['entity keys'][$key])) {
			if ($value !== false && $value !== null) {
				$this->base->{$entity_info['entity keys'][$key]} = $value;
				return $value;
			} elseif ($value === null) {
				unset($this->base->{$entity_info['entity keys'][$key]});
				return $value;
			} elseif (isset($this->base->{$entity_info['entity keys'][$key]})) {
				return $this->base->{$entity_info['entity keys'][$key]};
			}
		}
		return false;
	}

	/**
	 * Field
	 *
	 * Gets the value of the named field. Returns false in failure.
	 *
	 * @param string $name The name of the field to retrieve.
	 *
	 * @return mixed
	 */
	protected function field($name)
	{
		$args = func_get_args();
		array_shift($args);
		$func_args = array_merge(array($this->type(), $this->id(), $this->base, $name, !$this->full_loaded), $args);

		return forward_static_call_array(array('\AbleCore\Fields\FieldValueRegistry', 'field'), $func_args);
	}

	/**
	 * Reset Fields
	 *
	 * Removes all current instances of fields loaded on the entity. This is used to reset the
	 * cache when a new revision is loaded.
	 */
	protected function resetFields()
	{
		$instances = field_info_instances($this->type(), $this->bundle());
		foreach (array_keys($instances) as $field_name) {
			unset($this->base->$field_name);
		}
	}

	/**
	 * Load Full
	 *
	 * Loads the full entity.
	 */
	public function loadFull()
	{
		$this->base = entity_load_single($this->type(), $this->id());
		$this->full_loaded = true;
	}

} 
