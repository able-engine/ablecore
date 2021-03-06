<?php

namespace AbleCore\Fields;

class FieldValueRegistry
{
	private static $prepared = false;
	private static $registry = array();

	public static function handlesFieldType($type)
	{
		// Make sure we're prepared...
		if (!self::$prepared) {
			self::prepareConfiguration();
		}

		$handlers = self::getFieldHandlers($type);
		if (count($handlers) > 0) return true;

		return false;
	}

	public static function handleField($type, $value, $bundle)
	{
		// If we don't handle the type, return null.
		if (!self::handlesFieldType($type)) {
			return null;
		}

		// Get the handlers.
		$handlers = self::getFieldHandlers($type);
		return call_user_func_array($handlers[0], func_get_args());
	}

	/**
	 * Gets the value for a field on the specified entity.
	 *
	 * @param string $entity_type     The type of entity being loaded.
	 * @param int    $entity_id       The ID of the entity to get the field from.
	 * @param object $entity          The entity loaded from Drupal.
	 * @param string $name            The name of the field.
	 * @param bool   $autoload_fields Whether or not to autoload fields.
	 *
	 * @return FieldValueCollection|bool|null False if the field wasn't found,
	 *                                        null if the field has no values,
	 *                                        FieldValueCollection otherwise.
	 */
	public static function field($entity_type, $entity_id, $entity, $name, $autoload_fields = true)
	{
		// Get the field's information.
		$field_info = field_info_field($name);
		list(, , $bundle) = entity_extract_ids($entity_type, $entity);
		$field_instance_info = field_info_instance($entity_type, $name, $bundle);

		// Make sure the information is valid
		if ($field_info === null) return false;
		if ($field_instance_info === null) return false;

		// Get the type for the field.
		$type = $field_info['type'];

		// Check to see if the handler handles it.
		if (!self::handlesFieldType($type))
			return false;

		// Make sure the field exists on the base.
		if (!property_exists($entity, $name)) {

			// If they elected to not autoload fields, return false indicating the field
			// does not exist.
			if ($autoload_fields === false) return false;

			try {
				field_attach_load_revision($entity_type,
					array($entity_id => $entity),
					array('field_id' => $field_instance_info['field_id']));
			} catch (\Exception $ex) {
				// If an exception was thrown, that probably means we haven't yet saved the
				// node, so if there is no field on the currently-loaded node object, then
				// there won't be a field.
				return false;
			}
			if (!property_exists($entity, $name)) return false;

		}

		// Get the items for the field.
		$items = field_get_items($entity_type, $entity, $name);

		// If there are no items for the field, return null.
		if ($items === false)
			return null;

		// Prepare the arguments.
		$args = func_get_args();
		array_shift($args);
		array_shift($args);
		array_shift($args);
		array_shift($args);
		array_shift($args);

		// Create a new FieldValueCollection with the parsed values.
		$valueCollection = new FieldValueCollection();
		foreach ($items as $offset => $valueConfig) {
			$func_args = array($type, $valueConfig, $name);
			foreach ($args as $arg) {
				$func_args[] = $arg;
			}
			$valueCollection[$offset] = forward_static_call_array(
				array(__CLASS__, 'handleField'),
				$func_args
			);
		}

		// Make sure the value collection is not null.
		if (!array_filter($valueCollection->getArrayCopy())) return null;

		return $valueCollection;
	}

	protected static function getFieldHandlers($type)
	{
		$result = array();
		foreach (self::$registry as $className => $class) {
			if (array_key_exists($type, $class)) {
				if (method_exists($className, $class[$type])) {
					$result[] = array($className, $class[$type]);
				}
			}
		}
		return $result;
	}

	protected static function prepareConfiguration()
	{
		// Load all files inside the FieldHandlerTypes folder.
		foreach (glob(__DIR__ . '/FieldHandlerTypes/*.php') as $file)
			require_once($file);

		// Get all classes that subclass this one.
		foreach (get_declared_classes() as $class) {
			if (is_subclass_of($class, 'AbleCore\Fields\FieldValueHandler')) {
				if (property_exists($class, 'configuration')) {
					self::$registry[$class] = $class::$configuration;
				}
			}
		}
	}
}
