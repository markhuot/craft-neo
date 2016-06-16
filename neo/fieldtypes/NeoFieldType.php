<?php
namespace Craft;

require CRAFT_PLUGINS_PATH . '/neo/etc/Neo_BlockCacheDependency.php';

/**
 * Class NeoFieldType
 *
 * @package Craft
 */
class NeoFieldType extends BaseFieldType implements IEagerLoadingFieldType
{
	// Public methods

	public function getName()
	{
		return "Neo";
	}

	/**
	 * Disables using the built-in content column for storing values. We will manage field values ourselves.
	 *
	 * @return bool
	 */
	public function defineContentAttribute()
	{
		return false;
	}

	/**
	 * Prepares the Neo field settings before they're saved to the database.
	 * Handles preparing block types, field layouts and groups.
	 *
	 * @param array $settings
	 * @return Neo_SettingsModel
	 */
	public function prepSettings($settings)
	{
		if($settings instanceof Neo_SettingsModel)
		{
			return $settings;
		}

		$neoSettings = new Neo_SettingsModel();
		$neoSettings->setField($this->model);
		$blockTypes = [];
		$groups = [];

		if(!empty($settings['blockTypes']))
		{
			foreach($settings['blockTypes'] as $blockTypeId => $blockTypeSettings)
			{
				$blockType = new Neo_BlockTypeModel();
				$blockType->id = $blockTypeId;
				$blockType->fieldId = $this->model->id;
				$blockType->name = $blockTypeSettings['name'];
				$blockType->handle = $blockTypeSettings['handle'];
				$blockType->maxBlocks = $blockTypeSettings['maxBlocks'];
				$blockType->sortOrder = $blockTypeSettings['sortOrder'];
				$blockType->childBlocks = $blockTypeSettings['childBlocks'];
				$blockType->topLevel = (bool)$blockTypeSettings['topLevel'];

				if(!empty($blockTypeSettings['fieldLayout']))
				{
					$fieldLayoutPost = $blockTypeSettings['fieldLayout'];
					$requiredFieldPost = empty($blockTypeSettings['requiredFields']) ? [] : $blockTypeSettings['requiredFields'];

					$fieldLayout = craft()->fields->assembleLayout($fieldLayoutPost, $requiredFieldPost);
					$fieldLayout->type = Neo_ElementType::NeoBlock;
					$blockType->setFieldLayout($fieldLayout);
				}

				$blockTypes[] = $blockType;
			}
		}

		if(!empty($settings['groups']))
		{
			$names = $settings['groups']['name'];
			$sortOrders = $settings['groups']['sortOrder'];

			for($i = 0; $i < count($names); $i++)
			{
				$group = new Neo_GroupModel();
				$group->name = $names[$i];
				$group->sortOrder = $sortOrders[$i];

				$groups[] = $group;
			}
		}

		$neoSettings->setBlockTypes($blockTypes);
		$neoSettings->setGroups($groups);

		if(!empty($settings['maxBlocks']))
		{
			$neoSettings->maxBlocks = $settings['maxBlocks'];
		}

		return $neoSettings;
	}

	/**
	 * Prepares the field's value for use in templates.
	 *
	 * @param array $value
	 * @return Neo_CriteriaModel
	 */
	public function prepValue($value)
	{
		$criteria = craft()->neo->getCriteria();

		if(!empty($this->element->id))
		{
			$criteria->ownerId = $this->element->id;
		} else
		{
			$criteria->id = false;
		}

		$criteria->fieldId = $this->model->id;
		$criteria->locale = $this->element->locale;

		if(is_array($value) || $value === '')
		{
			$criteria->status = null;
			$criteria->localeEnabled = null;
			$criteria->limit = null;

			if(is_array($value))
			{
				$prevElement = null;

				foreach($value as $element)
				{
					if($prevElement)
					{
						$prevElement->setNext($element);
						$element->setPrev($prevElement);
					}

					$prevElement = $element;
				}

				foreach($value as $element)
				{
					$element->setAllElements($value);
				}

				$criteria->setMatchedElements($value);
				$criteria->setAllElements($value);
			} else if($value === '')
			{
				$criteria->setMatchedElements([]);
			}
		}

		return $criteria;
	}

	/**
	 * Converts the field's input value from post data to what it should be stored as in the database.
	 *
	 * @param array $data
	 * @return array
	 */
	public function prepValueFromPost($data)
	{
		$blockTypes = craft()->neo->getBlockTypesByFieldId($this->model->id, 'handle');

		if(!is_array($data))
		{
			return [];
		}

		$oldBlocksById = [];

		if(!empty($this->element->id))
		{
			$ownerId = $this->element->id;
			$ids = [];

			foreach(array_keys($data) as $blockId)
			{
				if(is_numeric($blockId) && $blockId != 0)
				{
					$ids[] = $blockId;
				}
			}

			if($ids)
			{
				$criteria = craft()->elements->getCriteria(Neo_ElementType::NeoBlock);
				$criteria->fieldId = $this->model->id;
				$criteria->ownerId = $ownerId;
				$criteria->id = $ids;
				$criteria->limit = null;
				$criteria->status = null;
				$criteria->localeEnabled = null;
				$criteria->locale = $this->element->locale;
				$oldBlocks = $criteria->find();

				foreach($oldBlocks as $oldBlock)
				{
					$oldBlocksById[$oldBlock->id] = $oldBlock;
				}
			}
		} else
		{
			$ownerId = null;
		}

		$blocks = [];

		foreach($data as $blockId => $blockData)
		{
			if(!isset($blockData['type']) || !isset($blockTypes[$blockData['type']]))
			{
				continue;
			}

			$blockType = $blockTypes[$blockData['type']];

			if(strncmp($blockId, 'new', 3) === 0 || !isset($oldBlocksById[$blockId]))
			{
				$block = new Neo_BlockModel();
				$block->fieldId = $this->model->id;
				$block->typeId = $blockType->id;
				$block->ownerId = $ownerId;
				$block->ownerLocale = $this->element->locale;
			} else
			{
				$block = $oldBlocksById[$blockId];
				$block->modified = (isset($blockData['modified']) ? (bool)$blockData['modified'] : true);
			}

			$block->setOwner($this->element);
			$block->enabled = (isset($blockData['enabled']) ? (bool)$blockData['enabled'] : true);
			$block->collapsed = (isset($blockData['collapsed']) ? (bool)$blockData['collapsed'] : false);
			$block->level = (isset($blockData['level']) ? intval($blockData['level']) : 0) + 1;

			$ownerContentPostLocation = $this->element->getContentPostLocation();

			if($ownerContentPostLocation)
			{
				$block->setContentPostLocation("{$ownerContentPostLocation}.{$this->model->handle}.{$blockId}.fields");
			}

			if(isset($blockData['fields']))
			{
				$block->setContentFromPost($blockData['fields']);
			}

			$blocks[] = $block;
		}

		return $blocks;
	}

	/**
	 * Validates the field's value (blocks).
	 *
	 * @param array $blocks
	 * @return array|bool
	 */
	public function validate($blocks)
	{
		$errors = [];
		$blocksValidate = true;

		foreach($blocks as $block)
		{
			if(!craft()->neo->validateBlock($block))
			{
				$blocksValidate = false;
			}
		}

		if(!$blocksValidate)
		{
			$errors[] = Craft::t("Correct the errors listed above.");
		}

		$maxBlocks = $this->getSettings()->maxBlocks;

		if($maxBlocks && count($blocks) > $maxBlocks)
		{
			if($maxBlocks == 1)
			{
				$errors[] = Craft::t("There can’t be more than one block.");
			} else
			{
				$errors[] = Craft::t("There can’t be more than {max} blocks.", ['max' => $maxBlocks]);
			}
		}

		// TODO validate individual blocktype max blocks

		return $errors ? $errors : true;
	}

	/**
	 * Builds the HTML for the configurator.
	 *
	 * @return string
	 */
	public function getSettingsHtml()
	{
		// Disable creating Neo fields inside Matrix, SuperTable and potentially other field-grouping field types.
		if($this->_getNamespaceDepth() > 2)
		{
			return '<span class="error">' . Craft::t("Unable to nest Neo fields.") . '</span>';
		}

		$settings = $this->getSettings();
		$jsBlockTypes = [];
		$jsGroups = [];

		foreach($settings->getBlockTypes() as $blockType)
		{
			$fieldLayout = $blockType->getFieldLayout();
			$fieldLayoutTabs = $fieldLayout->getTabs();

			$jsFieldLayout = [];

			foreach($fieldLayoutTabs as $tab)
			{
				$tabFields = $tab->getFields();
				$jsTabFields = [];

				foreach($tabFields as $field)
				{
					$jsTabFields[] = [
						'id' => $field->fieldId,
						'required' => $field->required,
					];
				}

				$jsFieldLayout[] = [
					'name' => $tab->name,
					'fields' => $jsTabFields,
				];
			}

			$jsBlockTypes[] = [
				'id' => $blockType->id,
				'sortOrder' => $blockType->sortOrder,
				'name' => $blockType->name,
				'handle' => $blockType->handle,
				'maxBlocks' => $blockType->maxBlocks,
				'childBlocks' => $blockType->childBlocks,
				'topLevel' => (bool)$blockType->topLevel,
				'errors' => $blockType->getErrors(),
				'fieldLayout' => $jsFieldLayout,
				'fieldLayoutId' => $fieldLayout->id,
			];
		}

		foreach($settings->getGroups() as $group)
		{
			$jsGroups[] = [
				'id' => $group->id,
				'sortOrder' => $group->sortOrder,
				'name' => $group->name,
			];
		}

		// Render the field layout designer HTML, but disregard any Javascript it outputs, as that'll be handled by Neo.

		craft()->templates->startJsBuffer();

		$fieldLayoutHtml = craft()->templates->render('_includes/fieldlayoutdesigner', [
			'fieldLayout' => false,
			'instructions' => '',
		]);

		craft()->templates->clearJsBuffer();

		$this->_includeResources('configurator', [
			'namespace' => craft()->templates->getNamespace(),
			'blockTypes' => $jsBlockTypes,
			'groups' => $jsGroups,
			'fieldLayoutHtml' => $fieldLayoutHtml,
		]);

		craft()->templates->includeTranslations(
			"Block Types",
			"Block type",
			"Group",
			"Settings",
			"Field Layout",
			"Reorder",
			"Name",
			"What this block type will be called in the CP.",
			"Handle",
			"How you'll refer to this block type in the templates.",
			"Max Blocks",
			"The maximum number of blocks of this type the field is allowed to have.",
			"All",
			"Child Blocks",
			"Which block types do you want to allow as children?",
			"Top Level",
			"Will this block type be allowed at the top level?",
			"Delete block type",
			"This can be left blank if you just want an unlabeled separator.",
			"Delete group"
		);

		return craft()->templates->render('neo/_fieldtype/settings', [
			'settings' => $this->getSettings(),
		]);
	}

	/**
	 * Builds the HTML for the input field.
	 *
	 * @param string $name
	 * @param array $value
	 * @return string
	 */
	public function getInputHtml($name, $value)
	{
		if($this->_getNamespaceDepth() > 1)
		{
			return '<span class="error">' . Craft::t("Unable to nest Neo fields.") . '</span>';
		}

		$id = craft()->templates->formatInputId($name);
		$settings = $this->getSettings();

		if($value instanceof ElementCriteriaModel)
		{
			$value->limit = null;
			$value->status = null;
			$value->localeEnabled = null;
		} else if(!$value)
		{
			$value = [];
		}

		$html = craft()->templates->render('neo/_fieldtype/input', [
			'id' => $id,
			'name' => $name,
			'blockTypes' => $settings->getBlockTypes(),
			'blocks' => $value,
			'static' => false
		]);

		$this->_prepareInputHtml($id, $name, $settings, $value);

		return $html;
	}

	/**
	 * Builds the HTML for the non-editable input field.
	 *
	 * @param array $value
	 * @return string
	 */
	public function getStaticHtml($value)
	{
		if($value)
		{
			$settings = $this->getSettings();
			$id = StringHelper::randomString();

			$html = craft()->templates->render('neo/_fieldtype/input', [
				'id' => $id,
				'name' => $id,
				'blockTypes' => $settings->getBlockTypes(),
				'blocks' => $value,
				'static' => true,
			]);

			$this->_prepareInputHtml($id, $id, $settings, $value, true);

			return $html;
		} else
		{
			return '<p class="light">' . Craft::t("No blocks.") . '</p>';
		}
	}

	/**
	 * Returns an array that maps source-to-target element IDs based on this custom field.
	 *
	 * @param BaseElementModel[] $sourceElements
	 * @return array
	 */
	public function getEagerLoadingMap($sourceElements)
	{
		$sourceElementIds = [];

		foreach($sourceElements as $sourceElement)
		{
			$sourceElementIds[] = $sourceElement->id;
		}

		// Return any relation data on these elements, defined with this field
		$map = craft()->db->createCommand()
			->select('ownerId as source, id as target')
			->from('neoblocks')
			->where(
				['and', 'fieldId=:fieldId', ['in', 'ownerId', $sourceElementIds]],
				[':fieldId' => $this->model->id]
			)
			// ->order('sortOrder') // TODO Need to join the structure elements table to get `lft` for ordering
			->queryAll();

		return [
			'elementType' => Neo_ElementType::NeoBlock,
			'map' => $map,
			'criteria' => ['fieldId' => $this->model->id],
		];
	}

	/**
	 * Returns the search keywords that should be associated with this field.
	 *
	 * @param array $value
	 * @return string
	 */
	public function getSearchKeywords($value)
	{
		craft()->tasks->createTask('Neo_GetSearchKeywords', null, [
			'fieldId' => $this->model->id,
			'ownerId' => $this->element->id,
			'locale' => $this->element->locale,
		]);

		return ''; // TODO return current keywords instead
	}

	/**
	 * Modifies the element query.
	 *
	 * @param DbCommand $query
	 * @param mixed $value
	 * @return bool|null
	 */
	public function modifyElementsQuery(DbCommand $query, $value)
	{
		if($value == 'not :empty:')
		{
			$value = ':notempty:';
		}

		if($value == ':notempty:' || $value == ':empty:')
		{
			$alias = 'neoblocks_' . $this->model->handle;
			$operator = ($value == ':notempty:' ? '!=' : '=');

			$query->andWhere(
				"(select count({$alias}.id) from {{neoblocks}} {$alias} where {$alias}.ownerId = elements.id and {$alias}.fieldId = :fieldId) {$operator} 0",
				[':fieldId' => $this->model->id]
			);
		}

		return $value !== null ? false : null;
	}


	// Events

	/**
	 * Saves the actual field type settings after the field type is saved.
	 */
	public function onAfterSave()
	{
		craft()->neo->saveSettings($this->getSettings(), false);
	}

	/**
	 * Cleans up the field and all it's settings after it gets deleted.
	 */
	public function onBeforeDelete()
	{
		craft()->neo->deleteField($this->model);
	}

	/**
	 *
	 */
	public function onAfterElementSave()
	{
		craft()->neo->saveFieldValue($this);
	}


	// Protected methods

	protected function getSettingsModel()
	{
		$settings = new Neo_SettingsModel($this->model);
		$settings->setField($this->model);

		return $settings;
	}


	// Private methods

	/**
	 * Returns what current depth the field is nested.
	 * For example, if a Neo field was being rendered inside a Matrix block, it's depth will be 2.
	 *
	 * @return int
	 */
	private function _getNamespaceDepth()
	{
		$namespace = craft()->templates->getNamespace();

		return preg_match_all('/\\bfields\\b/', $namespace);
	}

	/**
	 * Includes the resource (Javascript) files for when outputting the settings or input HTML.
	 *
	 * @param string $class - Either "configurator" or "input"
	 * @param array $settings - Settings to be JSON encoded and passed to the Javascript
	 */
	private function _includeResources($class, $settings = [])
	{
		craft()->templates->includeJsResource('neo/polyfill.js');
		craft()->templates->includeJsResource('neo/main.js');
		craft()->templates->includeJs('new Neo.' . ucfirst($class) . '(' . JsonHelper::encode($settings) . ')');
	}

	/**
	 * Actually builds the HTML for the input field. The other one just calls this method.
	 *
	 * @param int $id
	 * @param string $name
	 * @param array $settings
	 * @param array $value
	 * @param bool|false $static
	 */
	private function _prepareInputHtml($id, $name, $settings, $value, $static = false)
	{
		$headHtml = craft()->templates->getHeadHtml();
		$footHtml = craft()->templates->getFootHtml();

		$blockTypeInfo = [];
		foreach($settings->getBlockTypes() as $blockType)
		{
			$fieldLayout = $blockType->getFieldLayout();
			$blockTypeInfo[] = [
				'id' => $blockType->id,
				'fieldLayoutId' => $fieldLayout->id,
				'sortOrder' => $blockType->sortOrder,
				'handle' => $blockType->handle,
				'name' => Craft::t($blockType->name),
				'maxBlocks' => $blockType->maxBlocks,
				'childBlocks' => $blockType->childBlocks,
				'topLevel' => (bool)$blockType->topLevel,
				'tabs' => $this->_getBlockTypeHtml($blockType, null, $name, $static),
			];
		}

		$groupInfo = [];
		foreach($settings->getGroups() as $group)
		{
			$groupInfo[] = [
				'sortOrder' => $group->sortOrder,
				'name' => $group->name,
			];
		}

		$blockInfo = [];
		$sortOrder = 0;

		foreach($value as $block)
		{
			$blockInfo[] = [
				'id' => $block->id,
				'blockType' => $block->getType()->handle,
				'sortOrder' => $sortOrder++,
				'collapsed' => (bool)$block->collapsed,
				'enabled' => (bool)$block->enabled,
				'level' => intval($block->level) - 1,
				'tabs' => $this->_getBlockHtml($block, $name, $static),
			];
		}

		craft()->templates->includeHeadHtml($headHtml);
		craft()->templates->includeFootHtml($footHtml);

		$this->_includeResources('input', [
			'namespace' => craft()->templates->namespaceInputName($name),
			'blockTypes' => $blockTypeInfo,
			'groups' => $groupInfo,
			'inputId' => craft()->templates->namespaceInputId($id),
			'maxBlocks' => $settings->maxBlocks,
			'blocks' => $blockInfo,
			'static' => $static,
		]);

		craft()->templates->includeTranslations(
			"Select",
			"Actions",
			"Add a block",
			"Add block above",
			"Are you sure you want to delete the selected blocks?",
			"Expand",
			"Collapse",
			"Enable",
			"Disable",
			"Disabled",
			"Delete",
			"Are you sure you want to delete the selected blocks?",
			"Reorder"
		);
	}

	/**
	 * Builds the HTML for an individual block type.
	 * If you don't pass in a block along with the type, then it'll render a base template to build real blocks from.
	 * If you do pass in a block, then it's current field values will be rendered as well.
	 *
	 * @precondition The template head and foot buffers must be empty
	 * @param Neo_BlockTypeModel $blockType
	 * @param Neo_BlockModel|null $block
	 * @param string $namespace
	 * @param bool|false $static
	 * @return array
	 */
	private function _getBlockTypeHtml(Neo_BlockTypeModel $blockType, Neo_BlockModel $block = null, $namespace = '', $static = false)
	{
		$cacheKey = implode(':', ['neoblock',
			$blockType->id,
			$block ? $block->id : '',
			$namespace,
			$static ? 's' : '',
		]);

		$cache = craft()->cache->get($cacheKey);

		if(!$cache)
		{
			$oldNamespace = craft()->templates->getNamespace();
			$newNamespace = craft()->templates->namespaceInputName($namespace . '[__NEOBLOCK__][fields]', $oldNamespace);
			craft()->templates->setNamespace($newNamespace);

			$tabsHtml = [];

			$fieldLayout = $blockType->getFieldLayout();
			$fieldLayoutTabs = $fieldLayout->getTabs();

			foreach($fieldLayoutTabs as $fieldLayoutTab)
			{
				$tabHtml = [
					'name' => Craft::t($fieldLayoutTab->name),
					'headHtml' => '',
					'bodyHtml' => '',
					'footHtml' => '',
					'errors' => [],
				];

				$fieldLayoutFields = $fieldLayoutTab->getFields();

				foreach($fieldLayoutFields as $fieldLayoutField)
				{
					$field = $fieldLayoutField->getField();
					$fieldType = $field->getFieldType();

					if($fieldType)
					{
						$fieldType->element = $block;
						$fieldType->setIsFresh($block == null);

						if($block)
						{
							$fieldErrors = $block->getErrors($field->handle);

							if(!empty($fieldErrors))
							{
								$tabHtml['errors'] = array_merge($tabHtml['errors'], $fieldErrors);
							}
						}
					}
				}

				$tabHtml['bodyHtml'] = craft()->templates->namespaceInputs(craft()->templates->render('_includes/fields', [
					'namespace' => null,
					'element' => $block,
					'fields' => $fieldLayoutFields,
					'static' => $static,
				]));

				foreach($fieldLayoutFields as $fieldLayoutField)
				{
					$fieldType = $fieldLayoutField->getField()->getFieldType();

					if($fieldType)
					{
						$fieldType->setIsFresh(null);
					}
				}

				$tabHtml['headHtml'] = craft()->templates->getHeadHtml();
				$tabHtml['footHtml'] = craft()->templates->getFootHtml();

				$tabsHtml[] = $tabHtml;
			}

			craft()->templates->setNamespace($oldNamespace);

			$cacheDependency = new Neo_BlockCacheDependency($blockType, $block);
			craft()->cache->set($cacheKey, $tabsHtml, null, $cacheDependency);
			$cache = $tabsHtml;
		}

		return $cache;
	}

	/**
	 * Builds the HTML for an individual block.
	 * Just a wrapper for the above `_getBlockTypeHtml` method.
	 *
	 * @param Neo_BlockModel $block
	 * @param null $namespace
	 * @param bool|false $static
	 * @return array
	 */
	private function _getBlockHtml(Neo_BlockModel $block, $namespace = null, $static = false)
	{
		return $this->_getBlockTypeHtml($block->getType(), $block, $namespace, $static);
	}
}
