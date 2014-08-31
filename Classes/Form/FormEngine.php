<?php
namespace PHORAX\Flat\Form;

/**
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\Form\Element\InlineElement;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Utility\IconUtility;
use TYPO3\CMS\Core\Html\HtmlParser;
use TYPO3\CMS\Core\Html\RteHtmlParser;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageQueue;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Utility\ArrayUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class FormEngine extends \TYPO3\CMS\Backend\Form\FormEngine {

	/**********************************************************
	 *
	 * Rendering of each TCEform field type
	 *
	 ************************************************************/
	/**
	 * Generation of TCEform elements of the type "input"
	 * This will render a single-line input form field, possibly with various control/validation features
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeInput($table, $field, $row, &$PA) {
		$config = $PA['fieldConf']['config'];
		$specConf = $this->getSpecConfFromString($PA['extra'], $PA['fieldConf']['defaultExtras']);
		$size = MathUtility::forceIntegerInRange($config['size'] ? $config['size'] : 30, 5, $this->maxInputWidth);
		$evalList = GeneralUtility::trimExplode(',', $config['eval'], TRUE);
		$classAndStyleAttributes = $this->formWidthAsArray($size);
		$fieldAppendix = '';
		$item = '';
		$cssClasses = array($classAndStyleAttributes['class']);
		$cssStyle = $classAndStyleAttributes['style'];
		if (!isset($config['checkbox'])) {
			$config['checkbox'] = '0';
			$checkboxIsset = FALSE;
		} else {
			$checkboxIsset = TRUE;
		}
		if (in_array('date', $evalList) || in_array('datetime', $evalList)) {
			if (in_array('datetime', $evalList)) {
				$class = 'datetime';
			} else {
				$class = 'date';
			}
			$dateRange = '';
			if (isset($config['range']['lower'])) {
				$dateRange .= ' lower-' . (int)$config['range']['lower'];
			}
			if (isset($config['range']['upper'])) {
				$dateRange .= ' upper-' . (int)$config['range']['upper'];
			}
			$inputId = uniqid('tceforms-' . $class . 'field-');
			$cssClasses[] = 'tceforms-textfield tceforms-' . $class . 'field' . $dateRange;
			$fieldAppendix = IconUtility::getSpriteIcon('actions-edit-pick-date', array(
				'style' => 'cursor:pointer;',
				'id' => 'picker-' . $inputId
			));
		} elseif (in_array('timesec', $evalList)) {
			$inputId = uniqid('tceforms-timesecfield-');
			$cssClasses[] = 'tceforms-textfield tceforms-timesecfield';
		} elseif (in_array('year', $evalList)) {
			$inputId = uniqid('tceforms-yearfield-');
			$cssClasses[] = 'tceforms-textfield tceforms-yearfield';
		} elseif (in_array('time', $evalList)) {
			$inputId = uniqid('tceforms-timefield-');
			$cssClasses[] = 'tceforms-textfield tceforms-timefield';
		} elseif (in_array('int', $evalList)) {
			$inputId = uniqid('tceforms-intfield-');
			$cssClasses[] = 'tceforms-textfield tceforms-intfield';
		} elseif (in_array('double2', $evalList)) {
			$inputId = uniqid('tceforms-double2field-');
			$cssClasses[] = 'tceforms-textfield tceforms-double2field';
		} else {
			$inputId = uniqid('tceforms-textfield-');
			$cssClasses[] = 'tceforms-textfield';
			if ($checkboxIsset === FALSE) {
				$config['checkbox'] = '';
			}
		}
		if (isset($config['wizards']['link'])) {
			$inputId = uniqid('tceforms-linkfield-');
			$cssClasses[] = 'tceforms-textfield tceforms-linkfield';
		} elseif (isset($config['wizards']['color'])) {
			$inputId = uniqid('tceforms-colorfield-');
			$cssClasses[] = 'tceforms-textfield tceforms-colorfield';
		}
		if ($this->renderReadonly || $config['readOnly']) {
			$itemFormElValue = $PA['itemFormElValue'];
			if (in_array('date', $evalList)) {
				$config['format'] = 'date';
			} elseif (in_array('datetime', $evalList)) {
				$config['format'] = 'datetime';
			} elseif (in_array('time', $evalList)) {
				$config['format'] = 'time';
			}
			if (in_array('password', $evalList)) {
				$itemFormElValue = $itemFormElValue ? '*********' : '';
			}
			return $this->getSingleField_typeNone_render($config, $itemFormElValue);
		}
		foreach ($evalList as $func) {
			switch ($func) {
				case 'required':
					$this->registerRequiredProperty('field', $table . '_' . $row['uid'] . '_' . $field, $PA['itemFormElName']);
					// Mark this field for date/time disposal:
					if (array_intersect($evalList, array('date', 'datetime', 'time'))) {
						$this->requiredAdditional[$PA['itemFormElName']]['isPositiveNumber'] = TRUE;
					}
					break;
				default:
					// Pair hook to the one in \TYPO3\CMS\Core\DataHandling\DataHandler::checkValue_input_Eval()
					$evalObj = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tce']['formevals'][$func] . ':&' . $func);
					if (is_object($evalObj) && method_exists($evalObj, 'deevaluateFieldValue')) {
						$_params = array(
							'value' => $PA['itemFormElValue']
						);
						$PA['itemFormElValue'] = $evalObj->deevaluateFieldValue($_params);
					}
			}
		}
		$paramsList = '\'' . $PA['itemFormElName'] . '\',\'' . implode(',', $evalList) . '\',\'' . trim($config['is_in']) . '\',' . (isset($config['checkbox']) ? 1 : 0) . ',\'' . $config['checkbox'] . '\'';
		if (in_array('date', $evalList) || in_array('datetime', $evalList)) {
			$item .= '<span class="t3-tceforms-input-wrapper-datetime" onmouseOver="if (document.getElementById(\'' . $inputId . '\').value) {this.className=\'t3-tceforms-input-wrapper-datetime-hover\';} else {this.className=\'t3-tceforms-input-wrapper-datetime\';};" onmouseOut="this.className=\'t3-tceforms-input-wrapper-datetime\';">';
			// Add server timezone offset to UTC to our stored date
			if ($PA['itemFormElValue'] > 0) {
				$PA['itemFormElValue'] += date('Z', $PA['itemFormElValue']);
			}
		} else {
			$item .= '<span class="t3-tceforms-input-wrapper" onmouseOver="if (document.getElementById(\'' . $inputId . '\').value) {this.className=\'t3-tceforms-input-wrapper-hover\';} else {this.className=\'t3-tceforms-input-wrapper\';};" onmouseOut="this.className=\'t3-tceforms-input-wrapper\';">';
		}
		$PA['fieldChangeFunc'] = array_merge(array('typo3form.fieldGet' => 'typo3form.fieldGet(' . $paramsList . ');'), $PA['fieldChangeFunc']);
		// Old function "checkbox" now the option to set the date / remove the date
		if (isset($config['checkbox'])) {
			$item .= IconUtility::getSpriteIcon('actions-input-clear', array('tag' => 'a', 'class' => 't3-tceforms-input-clearer', 'onclick' => 'document.getElementById(\'' . $inputId . '\').value=\'\';document.getElementById(\'' . $inputId . '\').focus();' . implode('', $PA['fieldChangeFunc'])));
		}
		$mLgd = $config['max'] ?: 256;
		$iOnChange = implode('', $PA['fieldChangeFunc']);
		$cssClasses[] = 'hasDefaultValue';
		$item .= '<input type="text" ' . $this->getPlaceholderAttribute($table, $field, $config, $row) . 'id="' . $inputId . '" ' . 'class="' . implode(' ', $cssClasses) . '" ' . 'name="' . $PA['itemFormElName'] . '_hr" ' . 'value=""' . 'style="' . $cssStyle . '" ' . 'maxlength="' . $mLgd . '" ' . 'onchange="' . htmlspecialchars($iOnChange) . '"' . $PA['onFocus'] . ' />';
		// This is the EDITABLE form field.
		$item .= '<input type="hidden" name="' . $PA['itemFormElName'] . '" value="' . htmlspecialchars($PA['itemFormElValue']) . '" />';
		// This is the ACTUAL form field - values from the EDITABLE field must be transferred to this field which is the one that is written to the database.
		$item .= $fieldAppendix . '</span><div style="clear:both;"></div>';
		$this->extJSCODE .= 'typo3form.fieldSet(' . $paramsList . ');';
		// Going through all custom evaluations configured for this field
		foreach ($evalList as $evalData) {
			$evalObj = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tce']['formevals'][$evalData] . ':&' . $evalData);
			if (is_object($evalObj) && method_exists($evalObj, 'returnFieldJS')) {
				$this->extJSCODE .= '
TBE_EDITOR.customEvalFunctions[\'' . $evalData . '\'] = function(value) {
' . $evalObj->returnFieldJS() . '
}
';
			}
		}
		// Creating an alternative item without the JavaScript handlers.
		$altItem = '<input type="hidden" name="' . $PA['itemFormElName'] . '_hr" value="" />';
		$altItem .= '<input type="hidden" name="' . $PA['itemFormElName'] . '" value="' . htmlspecialchars($PA['itemFormElValue']) . '" />';
		// Wrap a wizard around the item?
		$item = $this->renderWizards(array($item, $altItem), $config['wizards'], $table, $row, $field, $PA, $PA['itemFormElName'] . '_hr', $specConf);

		return $item;
	}

	/**
	 * Renders a view widget to handle and activate NULL values.
	 * The widget is enabled by using 'null' in the 'eval' TCA definition.
	 *
	 * @param string $table Name of the table
	 * @param string $field Name of the field
	 * @param array $row Accordant data of the record row
	 * @param array $PA Parameters array with rendering instructions
	 * @return string Widget (if any).
	 */
	protected function renderNullValueWidget($table, $field, array $row, array $PA) {
		$widget = '';

		$config = $PA['fieldConf']['config'];
		if (
			!empty($config['eval']) && GeneralUtility::inList($config['eval'], 'null')
			&& (empty($config['mode']) || $config['mode'] !== 'useOrOverridePlaceholder')
		) {
			$checked = $PA['itemFormElValue'] === NULL ? '' : ' checked="checked"';
			$onChange = htmlspecialchars(
				'typo3form.fieldSetNull(\'' . $PA['itemFormElName'] . '\', !this.checked)'
			);

			$widget = '<span class="t3-tceforms-widget-null-wrapper">' .
				'<input type="hidden" name="' . $PA['itemFormElNameActive'] . '" value="0" />' .
				'<input type="checkbox" name="' . $PA['itemFormElNameActive'] . '" value="1" onchange="' . $onChange . '"' . $checked . ' />' .
			'</span>';
		}

		return $widget;
	}

	/**
	 * Determines whether the current field value is considered as NULL value.
	 * Using NULL values is enabled by using 'null' in the 'eval' TCA definition.
	 *
	 * @param string $table Name of the table
	 * @param string $field Name of the field
	 * @param array $row Accordant data
	 * @param array $PA Parameters array with rendering instructions
	 * @return boolean
	 */
	protected function isDisabledNullValueField($table, $field, array $row, array $PA) {
		$result = FALSE;

		$config = $PA['fieldConf']['config'];
		if ($PA['itemFormElValue'] === NULL && !empty($config['eval'])
			&& GeneralUtility::inList($config['eval'], 'null')
			&& (empty($config['mode']) || $config['mode'] !== 'useOrOverridePlaceholder')) {

			$result = TRUE;
		}

		return $result;
	}

	/**
	 * Generation of TCEform elements of the type "text"
	 * This will render a <textarea> OR RTE area form field, possibly with various control/validation features
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeText($table, $field, $row, &$PA) {
		// Init config:
		$config = $PA['fieldConf']['config'];
		$evalList = GeneralUtility::trimExplode(',', $config['eval'], TRUE);
		if ($this->renderReadonly || $config['readOnly']) {
			return $this->getSingleField_typeNone_render($config, $PA['itemFormElValue']);
		}
		// Setting columns number:
		$cols = MathUtility::forceIntegerInRange($config['cols'] ? $config['cols'] : 30, 5, $this->maxTextareaWidth);
		// Setting number of rows:
		$origRows = ($rows = MathUtility::forceIntegerInRange($config['rows'] ? $config['rows'] : 5, 1, 20));
		if (strlen($PA['itemFormElValue']) > $this->charsPerRow * 2) {
			$cols = $this->maxTextareaWidth;
			$rows = MathUtility::forceIntegerInRange(round(strlen($PA['itemFormElValue']) / $this->charsPerRow), count(explode(LF, $PA['itemFormElValue'])), 20);
			if ($rows < $origRows) {
				$rows = $origRows;
			}
		}
		if (in_array('required', $evalList)) {
			$this->requiredFields[$table . '_' . $row['uid'] . '_' . $field] = $PA['itemFormElName'];
		}
		// Init RTE vars:
		// Set TRUE, if the RTE is loaded; If not a normal textarea is shown.
		$RTEwasLoaded = 0;
		// Set TRUE, if the RTE would have been loaded if it wasn't for the disable-RTE flag in the bottom of the page...
		$RTEwouldHaveBeenLoaded = 0;
		// "Extra" configuration; Returns configuration for the field based on settings found in the "types" fieldlist. Traditionally, this is where RTE configuration has been found.
		$specConf = $this->getSpecConfFromString($PA['extra'], $PA['fieldConf']['defaultExtras']);
		// Setting up the altItem form field, which is a hidden field containing the value
		$altItem = '<input type="hidden" name="' . htmlspecialchars($PA['itemFormElName']) . '" value="' . htmlspecialchars($PA['itemFormElValue']) . '" />';
		$item = '';
		// If RTE is generally enabled (TYPO3_CONF_VARS and user settings)
		if ($this->RTEenabled) {
			$p = BackendUtility::getSpecConfParametersFromArray($specConf['rte_transform']['parameters']);
			// If the field is configured for RTE and if any flag-field is not set to disable it.
			if (isset($specConf['richtext']) && (!$p['flag'] || !$row[$p['flag']])) {
				BackendUtility::fixVersioningPid($table, $row);
				list($tscPID, $thePidValue) = $this->getTSCpid($table, $row['uid'], $row['pid']);
				// If the pid-value is not negative (that is, a pid could NOT be fetched)
				if ($thePidValue >= 0) {
					$RTEsetup = $this->getBackendUserAuthentication()->getTSConfig('RTE', BackendUtility::getPagesTSconfig($tscPID));
					$RTEtypeVal = BackendUtility::getTCAtypeValue($table, $row);
					$thisConfig = BackendUtility::RTEsetup($RTEsetup['properties'], $table, $field, $RTEtypeVal);
					if (!$thisConfig['disabled']) {
						if (!$this->disableRTE) {
							$this->RTEcounter++;
							// Find alternative relative path for RTE images/links:
							$eFile = RteHtmlParser::evalWriteFile($specConf['static_write'], $row);
							$RTErelPath = is_array($eFile) ? dirname($eFile['relEditFile']) : '';
							// Get RTE object, draw form and set flag:
							$RTEobj = BackendUtility::RTEgetObj();
							$item = $RTEobj->drawRTE($this, $table, $field, $row, $PA, $specConf, $thisConfig, $RTEtypeVal, $RTErelPath, $thePidValue);
							// Wizard:
							$item = $this->renderWizards(array($item, $altItem), $config['wizards'], $table, $row, $field, $PA, $PA['itemFormElName'], $specConf, 1);
							$RTEwasLoaded = 1;
						} else {
							$RTEwouldHaveBeenLoaded = 1;
							$this->commentMessages[] = $PA['itemFormElName'] . ': RTE is disabled by the on-page RTE-flag (probably you can enable it by the check-box in the bottom of this page!)';
						}
					} else {
						$this->commentMessages[] = $PA['itemFormElName'] . ': RTE is disabled by the Page TSconfig, "RTE"-key (eg. by RTE.default.disabled=0 or such)';
					}
				} else {
					$this->commentMessages[] = $PA['itemFormElName'] . ': PID value could NOT be fetched. Rare error, normally with new records.';
				}
			} else {
				if (!isset($specConf['richtext'])) {
					$this->commentMessages[] = $PA['itemFormElName'] . ': RTE was not configured for this field in TCA-types';
				}
				if (!(!$p['flag'] || !$row[$p['flag']])) {
					$this->commentMessages[] = $PA['itemFormElName'] . ': Field-flag (' . $PA['flag'] . ') has been set to disable RTE!';
				}
			}
		}
		// Display ordinary field if RTE was not loaded.
		if (!$RTEwasLoaded) {
			// Show message, if no RTE (field can only be edited with RTE!)
			if ($specConf['rte_only']) {
				$item = '<p><em>' . htmlspecialchars($this->getLL('l_noRTEfound')) . '</em></p>';
			} else {
				if ($specConf['nowrap']) {
					$wrap = 'off';
				} else {
					$wrap = $config['wrap'] ?: 'virtual';
				}
				$classes = array();
				if ($specConf['fixed-font']) {
					$classes[] = 'fixed-font';
				}
				if ($specConf['enable-tab']) {
					$classes[] = 'enable-tab';
				}
				$formWidthText = $this->formWidthText($cols, $wrap);
				// Extract class attributes from $formWidthText (otherwise it would be added twice to the output)
				$res = array();
				if (preg_match('/ class="(.+?)"/', $formWidthText, $res)) {
					$formWidthText = str_replace(' class="' . $res[1] . '"', '', $formWidthText);
					$classes = array_merge($classes, explode(' ', $res[1]));
				}
				if (count($classes)) {
					$class = ' class="tceforms-textarea ' . implode(' ', $classes) . '"';
				} else {
					$class = 'tceforms-textarea';
				}
				$evalList = GeneralUtility::trimExplode(',', $config['eval'], TRUE);
				foreach ($evalList as $func) {
					switch ($func) {
						case 'required':
							$this->registerRequiredProperty('field', $table . '_' . $row['uid'] . '_' . $field, $PA['itemFormElName']);
							break;
						default:
							// Pair hook to the one in \TYPO3\CMS\Core\DataHandling\DataHandler::checkValue_input_Eval()
							// and \TYPO3\CMS\Core\DataHandling\DataHandler::checkValue_text_Eval()
							$evalObj = GeneralUtility::getUserObj($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tce']['formevals'][$func] . ':&' . $func);
							if (is_object($evalObj) && method_exists($evalObj, 'deevaluateFieldValue')) {
								$_params = array(
									'value' => $PA['itemFormElValue']
								);
								$PA['itemFormElValue'] = $evalObj->deevaluateFieldValue($_params);
							}
					}
				}
				$iOnChange = implode('', $PA['fieldChangeFunc']);
				$item .= '
							<textarea ' . 'id="' . uniqid('tceforms-textarea-') . '" ' . 'name="' . $PA['itemFormElName']
					. '"' . $formWidthText . $class . ' ' . 'rows="' . $rows . '" ' . 'wrap="' . $wrap . '" ' . 'onchange="'
					. htmlspecialchars($iOnChange) . '"' . $this->getPlaceholderAttribute($table, $field, $config, $row)
					. $PA['onFocus'] . '>' . GeneralUtility::formatForTextarea($PA['itemFormElValue']) . '</textarea>';
				$item = $this->renderWizards(array($item, $altItem), $config['wizards'], $table, $row, $field, $PA,
					$PA['itemFormElName'], $specConf, $RTEwouldHaveBeenLoaded);
			}
		}
		// Return field HTML:
		return $item;
	}

	/**
	 * Generation of TCEform elements of the type "check"
	 * This will render a check-box OR an array of checkboxes
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeCheck($table, $field, $row, &$PA) {
		$config = $PA['fieldConf']['config'];
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		// Traversing the array of items:
		$selItems = $this->initItemArray($PA['fieldConf']);
		if ($config['itemsProcFunc']) {
			$selItems = $this->procItems($selItems, $PA['fieldTSConfig']['itemsProcFunc.'], $config, $table, $row, $field);
		}
		if (!count($selItems)) {
			$selItems[] = array('', '');
		}
		$thisValue = (int)$PA['itemFormElValue'];
		$cols = (int)$config['cols'];
		$selItemsCount = count($selItems);
		if ($cols > 1) {
			$item .= '<table border="0" cellspacing="0" cellpadding="0" class="typo3-TCEforms-checkboxArray">';
			for ($c = 0; $c < $selItemsCount; $c++) {
				$p = $selItems[$c];
				if (!($c % $cols)) {
					$item .= '<tr>';
				}
				$cBP = $this->checkBoxParams($PA['itemFormElName'], $thisValue, $c, count($selItems), implode('', $PA['fieldChangeFunc']));
				$cBName = $PA['itemFormElName'] . '_' . $c;
				$cBID = $PA['itemFormElID'] . '_' . $c;
				$item .= '<td nowrap="nowrap">' . '<input type="checkbox"' . $this->insertDefStyle('check')
					. ' value="1" name="' . $cBName . '"' . $cBP . $disabled . ' id="' . $cBID . '" />'
					. $this->wrapLabels(('<label for="' . $cBID . '">' . htmlspecialchars($p[0]) . '</label>&nbsp;'))
					. '</td>';
				if ($c % $cols + 1 == $cols) {
					$item .= '</tr>';
				}
			}
			if ($c % $cols) {
				$rest = $cols - $c % $cols;
				for ($c = 0; $c < $rest; $c++) {
					$item .= '<td></td>';
				}
				if ($c > 0) {
					$item .= '</tr>';
				}
			}
			$item .= '</table>';
		} else {
			for ($c = 0; $c < $selItemsCount; $c++) {
				$p = $selItems[$c];
				$cBP = $this->checkBoxParams($PA['itemFormElName'], $thisValue, $c, count($selItems), implode('', $PA['fieldChangeFunc']));
				$cBName = $PA['itemFormElName'] . '_' . $c;
				$cBID = $PA['itemFormElID'] . '_' . $c;
				$item .= ($c > 0 ? '<br />' : '') . '<input type="checkbox"' . $this->insertDefStyle('check')
					. ' value="1" name="' . $cBName . '"' . $cBP . $PA['onFocus'] . $disabled . ' id="' . $cBID . '" />'
					. $this->wrapLabels('<label for="' . $cBID . '">' . htmlspecialchars($p[0]) . '</label>');
			}
		}
		if (!$disabled) {
			$item .= '<input type="hidden" name="' . $PA['itemFormElName'] . '" value="' . htmlspecialchars($thisValue) . '" />';
		}
		return $item;
	}

	/**
	 * Generation of TCEform elements of the type "radio"
	 * This will render a series of radio buttons.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeRadio($table, $field, $row, &$PA) {
		$config = $PA['fieldConf']['config'];
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		// Get items for the array:
		$selItems = $this->initItemArray($PA['fieldConf']);
		if ($config['itemsProcFunc']) {
			$selItems = $this->procItems($selItems, $PA['fieldTSConfig']['itemsProcFunc.'], $config, $table, $row, $field);
		}
		// Traverse the items, making the form elements:
		$selItemsCount = count($selItems);
		for ($c = 0; $c < $selItemsCount; $c++) {
			$p = $selItems[$c];
			$rID = $PA['itemFormElID'] . '_' . $c;
			$rOnClick = implode('', $PA['fieldChangeFunc']);
			$rChecked = (string)$p[1] === (string)$PA['itemFormElValue'] ? ' checked="checked"' : '';
			$item .= '<input type="radio"' . $this->insertDefStyle('radio') . ' name="' . $PA['itemFormElName']
				. '" value="' . htmlspecialchars($p[1]) . '" onclick="' . htmlspecialchars($rOnClick) . '"' . $rChecked
				. $PA['onFocus'] . $disabled . ' id="' . $rID . '" />
					<label for="' . $rID . '">' . htmlspecialchars($p[0]) . '</label>
					<br />';
		}
		return $item;
	}

	/**
	 * Generation of TCEform elements of the type "select"
	 * This will render a selector box element, or possibly a special construction with two selector boxes.
	 * That depends on configuration.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeSelect($table, $field, $row, &$PA) {
		// Field configuration from TCA:
		$config = $PA['fieldConf']['config'];
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		// "Extra" configuration; Returns configuration for the field based on settings found in the "types" fieldlist.
		$specConf = $this->getSpecConfFromString($PA['extra'], $PA['fieldConf']['defaultExtras']);
		$selItems = $this->getSelectItems($table, $field, $row, $PA);

		// Creating the label for the "No Matching Value" entry.
		$nMV_label = isset($PA['fieldTSConfig']['noMatchingValue_label'])
			? $this->sL($PA['fieldTSConfig']['noMatchingValue_label'])
			: '[ ' . $this->getLL('l_noMatchingValue') . ' ]';
		// Prepare some values:
		$maxitems = (int)$config['maxitems'];
		// If a SINGLE selector box...
		if ($maxitems <= 1 && $config['renderMode'] !== 'tree') {
			$item = $this->getSingleField_typeSelect_single($table, $field, $row, $PA, $config, $selItems, $nMV_label);
		} elseif ($config['renderMode'] === 'checkbox') {
			// Checkbox renderMode
			$item = $this->getSingleField_typeSelect_checkbox($table, $field, $row, $PA, $config, $selItems, $nMV_label);
		} elseif ($config['renderMode'] === 'singlebox') {
			// Single selector box renderMode
			$item = $this->getSingleField_typeSelect_singlebox($table, $field, $row, $PA, $config, $selItems, $nMV_label);
		} elseif ($config['renderMode'] === 'tree') {
			// Tree renderMode
			$treeClass = GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Form\\Element\\TreeElement', $this);
			$item = $treeClass->renderField($table, $field, $row, $PA, $config, $selItems, $nMV_label);
			// Register the required number of elements
			$minitems = MathUtility::forceIntegerInRange($config['minitems'], 0);
			$this->registerRequiredProperty('range', $PA['itemFormElName'], array($minitems, $maxitems, 'imgName' => $table . '_' . $row['uid'] . '_' . $field));
		} else {
			// Traditional multiple selector box:
			$item = $this->getSingleField_typeSelect_multiple($table, $field, $row, $PA, $config, $selItems, $nMV_label);
		}
		// Wizards:
		if (!$disabled) {
			$altItem = '<input type="hidden" name="' . $PA['itemFormElName'] . '" value="' . htmlspecialchars($PA['itemFormElValue']) . '" />';
			$item = $this->renderWizards(array($item, $altItem), $config['wizards'], $table, $row, $field, $PA, $PA['itemFormElName'], $specConf);
		}
		return $item;
	}

	/**
	 * Collects the items for a select field by reading the configured
	 * select items from the configuration and / or by collecting them
	 * from a foreign table.
	 *
	 * @param string $table The table name of the record
	 * @param string $fieldName The select field name
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return array
	 */
	public function getSelectItems($table, $fieldName, array $row, array $PA) {
		$config = $PA['fieldConf']['config'];

		// Getting the selector box items from the system
		$selectItems = $this->addSelectOptionsToItemArray(
			$this->initItemArray($PA['fieldConf']),
			$PA['fieldConf'],
			$this->setTSconfig($table, $row),
			$fieldName
		);

		// Possibly filter some items:
		$selectItems = GeneralUtility::keepItemsInArray(
			$selectItems,
			$PA['fieldTSConfig']['keepItems'],
			function ($value) {
				return $value[1];
			}
		);

		// Possibly add some items:
		$selectItems = $this->addItems($selectItems, $PA['fieldTSConfig']['addItems.']);

		// Process items by a user function:
		if (isset($config['itemsProcFunc']) && $config['itemsProcFunc']) {
			$selectItems = $this->procItems($selectItems, $PA['fieldTSConfig']['itemsProcFunc.'], $config, $table, $row, $fieldName);
		}

		// Possibly remove some items:
		$removeItems = GeneralUtility::trimExplode(',', $PA['fieldTSConfig']['removeItems'], TRUE);
		foreach ($selectItems as $selectItemIndex => $selectItem) {

			// Checking languages and authMode:
			$languageDeny = FALSE;
			$beUserAuth = $this->getBackendUserAuthentication();
			if (
				!empty($GLOBALS['TCA'][$table]['ctrl']['languageField'])
				&& $GLOBALS['TCA'][$table]['ctrl']['languageField'] === $fieldName
				&& !$beUserAuth->checkLanguageAccess($selectItem[1])
			) {
				$languageDeny = TRUE;
			}

			$authModeDeny = FALSE;
			if (
				($config['form_type'] === 'select')
				&& $config['authMode']
				&& !$beUserAuth->checkAuthMode($table, $fieldName, $selectItem[1], $config['authMode'])
			) {
				$authModeDeny = TRUE;
			}

			if (in_array($selectItem[1], $removeItems) || $languageDeny || $authModeDeny) {
				unset($selectItems[$selectItemIndex]);
			} elseif (isset($PA['fieldTSConfig']['altLabels.'][$selectItem[1]])) {
				$selectItems[$selectItemIndex][0] = htmlspecialchars($this->sL($PA['fieldTSConfig']['altLabels.'][$selectItem[1]]));
			}

			// Removing doktypes with no access:
			if (($table === 'pages' || $table === 'pages_language_overlay') && $fieldName === 'doktype') {
				if (!($beUserAuth->isAdmin() || GeneralUtility::inList($beUserAuth->groupData['pagetypes_select'], $selectItem[1]))) {
					unset($selectItems[$selectItemIndex]);
				}
			}
		}

		return $selectItems;
	}

	/**
	 * Creates a single-selector box
	 * (Render function for getSingleField_typeSelect())
	 *
	 * @param string $table See getSingleField_typeSelect()
	 * @param string $field See getSingleField_typeSelect()
	 * @param array $row See getSingleField_typeSelect()
	 * @param array $PA See getSingleField_typeSelect()
	 * @param array $config (Redundant) content of $PA['fieldConf']['config'] (for convenience)
	 * @param array $selItems Items available for selection
	 * @param string $nMV_label Label for no-matching-value
	 * @return string The HTML code for the item
	 * @see getSingleField_typeSelect()
	 * @todo Define visibility
	 */
	public function getSingleField_typeSelect_single($table, $field, $row, &$PA, $config, $selItems, $nMV_label) {
		// check against inline uniqueness
		$inlineParent = $this->inline->getStructureLevel(-1);
		$uniqueIds = NULL;
		if (is_array($inlineParent) && $inlineParent['uid']) {
			if ($inlineParent['config']['foreign_table'] == $table && $inlineParent['config']['foreign_unique'] == $field) {
				$uniqueIds = $this->inline->inlineData['unique'][$this->inline->inlineNames['object']
					. InlineElement::Structure_Separator . $table]['used'];
				$PA['fieldChangeFunc']['inlineUnique'] = 'inline.updateUnique(this,\'' . $this->inline->inlineNames['object']
					. InlineElement::Structure_Separator . $table . '\',\'' . $this->inline->inlineNames['form']
					. '\',\'' . $row['uid'] . '\');';
			}
			// hide uid of parent record for symmetric relations
			if (
				$inlineParent['config']['foreign_table'] == $table
				&& ($inlineParent['config']['foreign_field'] == $field || $inlineParent['config']['symmetric_field'] == $field)
			) {
				$uniqueIds[] = $inlineParent['uid'];
			}
		}
		// Initialization:
		$c = 0;
		$sI = 0;
		$noMatchingValue = 1;
		$opt = array();
		$selicons = array();
		$onlySelectedIconShown = 0;
		$size = (int)$config['size'];
		// Style set on <select/>
		$selectedStyle = '';
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
			$onlySelectedIconShown = 1;
		}
		// Register as required if minitems is greater than zero
		if (($minItems = MathUtility::forceIntegerInRange($config['minitems'], 0)) > 0) {
			$this->registerRequiredProperty('field', $table . '_' . $row['uid'] . '_' . $field, $PA['itemFormElName']);
		}

		// Icon configuration:
		if ($config['suppress_icons'] == 'IF_VALUE_FALSE') {
			$suppressIcons = !$PA['itemFormElValue'] ? 1 : 0;
		} elseif ($config['suppress_icons'] == 'ONLY_SELECTED') {
			$suppressIcons = 0;
			$onlySelectedIconShown = 1;
		} elseif ($config['suppress_icons']) {
			$suppressIcons = 1;
		} else {
			$suppressIcons = 0;
		}
		// Traverse the Array of selector box items:
		$optGroupStart = array();
		$optGroupOpen = FALSE;
		$classesForSelectTag = array();
		foreach ($selItems as $p) {
			$sM = (string)$PA['itemFormElValue'] === (string)$p[1] ? ' selected="selected"' : '';
			if ($sM) {
				$sI = $c;
				$noMatchingValue = 0;
			}
			// Getting style attribute value (for icons):
			$styleAttrValue = '';
			if ($config['iconsInOptionTags']) {
				$styleAttrValue = $this->optionTagStyle($p[2]);
				if ($sM) {
					list($selectIconFile, $selectIconInfo) = $this->getIcon($p[2]);
					if (!empty($selectIconInfo)) {
						$selectedStyle = ' style="background-image:url(' . $selectIconFile . ');"';
						$classesForSelectTag[] = 'typo3-TCEforms-select-selectedItemWithBackgroundImage';
					}
				}
			}
			// Compiling the <option> tag:
			if (!($p[1] != $PA['itemFormElValue'] && is_array($uniqueIds) && in_array($p[1], $uniqueIds))) {
				if ($p[1] === '--div--') {
					$optGroupStart[0] = $p[0];
					if ($config['iconsInOptionTags']) {
						$optGroupStart[1] = $this->optgroupTagStyle($p[2]);
					} else {
						$optGroupStart[1] = $styleAttrValue;
					}
				} else {
					if (count($optGroupStart)) {
						// Closing last optgroup before next one starts
						if ($optGroupOpen) {
							$opt[] = '</optgroup>' . LF;
						}
						$opt[] = '<optgroup label="' . htmlspecialchars($optGroupStart[0], ENT_COMPAT, 'UTF-8', FALSE)
							. '"' . ($optGroupStart[1] ? ' style="' . htmlspecialchars($optGroupStart[1]) . '"' : '')
							. ' class="c-divider">' . LF;
						$optGroupOpen = TRUE;
						$c--;
						$optGroupStart = array();
					}
					$opt[] = '<option value="' . htmlspecialchars($p[1]) . '"' . $sM
						. ($styleAttrValue ? ' style="' . htmlspecialchars($styleAttrValue) . '"' : '') . '>'
						. htmlspecialchars($p[0], ENT_COMPAT, 'UTF-8', FALSE) . '</option>' . LF;
				}
			}
			// If there is an icon for the selector box (rendered in selicon-table below)...:
			// if there is an icon ($p[2]), icons should be shown, and, if only selected are visible, is it selected
			if ($p[2] && !$suppressIcons && (!$onlySelectedIconShown || $sM)) {
				list($selIconFile, $selIconInfo) = $this->getIcon($p[2]);
				$iOnClick = $this->elName($PA['itemFormElName']) . '.selectedIndex=' . $c . '; ' . $this->elName($PA['itemFormElName']);
				$iOnClickOptions = $this->elName($PA['itemFormElName']) . '.options[' . $c . ']';
				if (empty($selIconInfo)) {
					$iOnClick .= '.className=' . $iOnClickOptions . '.className; ';
				} else {
					$iOnClick .= '.style.backgroundImage=' . $iOnClickOptions . '.style.backgroundImage; ';
				}
				$iOnClick .= implode('', $PA['fieldChangeFunc']) . 'this.blur(); return false;';
				$selicons[] = array(
					(!$onlySelectedIconShown ? '<a href="#" onclick="' . htmlspecialchars($iOnClick) . '">' : '')
						. $this->getIconHtml($p[2], htmlspecialchars($p[0]), htmlspecialchars($p[0]))
						. (!$onlySelectedIconShown ? '</a>' : ''),
					$c,
					$sM
				);
			}
			$c++;
		}
		// Closing optgroup if open
		if ($optGroupOpen) {
			$opt[] = '</optgroup>';
		}
		// No-matching-value:
		if ($PA['itemFormElValue'] && $noMatchingValue && !$PA['fieldTSConfig']['disableNoMatchingValueElement'] && !$config['disableNoMatchingValueElement']) {
			$nMV_label = @sprintf($nMV_label, $PA['itemFormElValue']);
			$opt[] = '<option value="' . htmlspecialchars($PA['itemFormElValue']) . '" selected="selected">' . htmlspecialchars($nMV_label) . '</option>';
		}
		// Create item form fields:
		$sOnChange = 'if (this.options[this.selectedIndex].value==\'--div--\') {this.selectedIndex=' . $sI . ';} ' . implode('', $PA['fieldChangeFunc']);
		if (!$disabled) {
			// MUST be inserted before the selector - else is the value of the hiddenfield here mysteriously submitted...
			$item .= '<input type="hidden" name="' . $PA['itemFormElName'] . '_selIconVal" value="' . htmlspecialchars($sI) . '" />';
		}
		if ($config['iconsInOptionTags']) {
			$classesForSelectTag[] = 'icon-select';
		}
		$item .= '<select' . $selectedStyle . ' id="' . uniqid('tceforms-select-') . '" name="' . $PA['itemFormElName'] . '"' . $this->insertDefStyle('select', implode(' ', $classesForSelectTag)) . ($size ? ' size="' . $size . '"' : '') . ' onchange="' . htmlspecialchars($sOnChange) . '"' . $PA['onFocus'] . $disabled . '>';
		$item .= implode('', $opt);
		$item .= '</select>';
		// Create icon table:
		if (count($selicons) && !$config['noIconsBelowSelect']) {
			$item .= '<table border="0" cellpadding="0" cellspacing="0" class="typo3-TCEforms-selectIcons">';
			$selicon_cols = (int)$config['selicon_cols'];
			if (!$selicon_cols) {
				$selicon_cols = count($selicons);
			}
			$sR = ceil(count($selicons) / $selicon_cols);
			$selicons = array_pad($selicons, $sR * $selicon_cols, '');
			for ($sa = 0; $sa < $sR; $sa++) {
				$item .= '<tr>';
				for ($sb = 0; $sb < $selicon_cols; $sb++) {
					$sk = $sa * $selicon_cols + $sb;
					$imgN = 'selIcon_' . $table . '_' . $row['uid'] . '_' . $field . '_' . $selicons[$sk][1];
					$imgS = $selicons[$sk][2] ? $this->backPath . 'gfx/content_selected.gif' : 'clear.gif';
					$item .= '<td><img name="' . htmlspecialchars($imgN) . '" src="' . $imgS . '" width="7" height="10" alt="" /></td>';
					$item .= '<td>' . $selicons[$sk][0] . '</td>';
				}
				$item .= '</tr>';
			}
			$item .= '</table>';
		}
		return $item;
	}

	/**
	 * Creates a checkbox list (renderMode = "checkbox")
	 * (Render function for getSingleField_typeSelect())
	 *
	 * @param string $table See getSingleField_typeSelect()
	 * @param string $field See getSingleField_typeSelect()
	 * @param array $row See getSingleField_typeSelect()
	 * @param array $PA See getSingleField_typeSelect()
	 * @param array $config (Redundant) content of $PA['fieldConf']['config'] (for convenience)
	 * @param array $selItems Items available for selection
	 * @param string $nMV_label Label for no-matching-value
	 * @return string The HTML code for the item
	 * @see getSingleField_typeSelect()
	 * @todo Define visibility
	 */
	public function getSingleField_typeSelect_checkbox($table, $field, $row, &$PA, $config, $selItems, $nMV_label) {
		if (empty($selItems)) {
			return '';
		}
		// Get values in an array (and make unique, which is fine because there can be no duplicates anyway):
		$itemArray = array_flip($this->extractValuesOnlyFromValueLabelList($PA['itemFormElValue']));
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		// Traverse the Array of selector box items:
		$tRows = array();
		$c = 0;
		$setAll = array();
		$unSetAll = array();
		$restoreCmd = array();
		$sOnChange = '';
		if (!$disabled) {
			$sOnChange = implode('', $PA['fieldChangeFunc']);
			// Used to accumulate the JS needed to restore the original selection.
			foreach ($selItems as $p) {
				// Non-selectable element:
				if ($p[1] === '--div--') {
					$selIcon = '';
					if (isset($p[2]) && $p[2] != 'empty-emtpy') {
						$selIcon = $this->getIconHtml($p[2]);
					}
					$tRows[] = '
						<tr class="c-header">
							<td colspan="3">' . $selIcon . htmlspecialchars($p[0]) . '</td>
						</tr>';
				} else {
					// Selected or not by default:
					$sM = '';
					if (isset($itemArray[$p[1]])) {
						$sM = ' checked="checked"';
						unset($itemArray[$p[1]]);
					}
					// Icon:
					if ($p[2]) {
						$selIcon = $p[2];
					} else {
						$selIcon = IconUtility::getSpriteIcon('empty-empty');
					}
					// Compile row:
					$rowId = uniqid('select_checkbox_row_');
					$onClickCell = $this->elName(($PA['itemFormElName'] . '[' . $c . ']')) . '.checked=!' . $this->elName(($PA['itemFormElName'] . '[' . $c . ']')) . '.checked;';
					$onClick = 'this.attributes.getNamedItem("class").nodeValue = ' . $this->elName(($PA['itemFormElName'] . '[' . $c . ']')) . '.checked ? "c-selectedItem" : "c-unselectedItem";';
					$setAll[] = $this->elName(($PA['itemFormElName'] . '[' . $c . ']')) . '.checked=1;';
					$setAll[] = '$(\'' . $rowId . '\').removeClassName(\'c-unselectedItem\');$(\'' . $rowId . '\').addClassName(\'c-selectedItem\');';
					$unSetAll[] = $this->elName(($PA['itemFormElName'] . '[' . $c . ']')) . '.checked=0;';
					$unSetAll[] = '$(\'' . $rowId . '\').removeClassName(\'c-selectedItem\');$(\'' . $rowId . '\').addClassName(\'c-unselectedItem\');';
					$restoreCmd[] = $this->elName(($PA['itemFormElName'] . '[' . $c . ']')) . '.checked=' . ($sM ? 1 : 0) . ';' . '$(\'' . $rowId . '\').removeClassName(\'c-selectedItem\');$(\'' . $rowId . '\').removeClassName(\'c-unselectedItem\');' . '$(\'' . $rowId . '\').addClassName(\'c-' . ($sM ? '' : 'un') . 'selectedItem\');';
					// Check if some help text is available
					// Since TYPO3 4.5 help text is expected to be an associative array
					// with two key, "title" and "description"
					// For the sake of backwards compatibility, we test if the help text
					// is a string and use it as a description (this could happen if items
					// are modified with an itemProcFunc)
					$hasHelp = FALSE;
					$help = '';
					$helpArray = array();
					if (is_array($p[3]) && count($p[3]) > 0 || !empty($p[3])) {
						$hasHelp = TRUE;
						if (is_array($p[3])) {
							$helpArray = $p[3];
						} else {
							$helpArray['description'] = $p[3];
						}
					}
					$label = htmlspecialchars($p[0], ENT_COMPAT, 'UTF-8', FALSE);
					if ($hasHelp) {
						$help = BackendUtility::wrapInHelp('', '', '', $helpArray);
					}
					$tRows[] = '
						<tr id="' . $rowId . '" class="' . ($sM ? 'c-selectedItem' : 'c-unselectedItem')
						. '" onclick="' . htmlspecialchars($onClick) . '" style="cursor: pointer;">
							<td class="c-checkbox"><input type="checkbox"' . $this->insertDefStyle('check')
						. ' name="' . htmlspecialchars(($PA['itemFormElName'] . '[' . $c . ']'))
						. '" value="' . htmlspecialchars($p[1]) . '"' . $sM . ' onclick="' . htmlspecialchars($sOnChange)
						. '"' . $PA['onFocus'] . ' /></td>
							<td class="c-labelCell" onclick="' . htmlspecialchars($onClickCell) . '">' . $this->getIconHtml($selIcon) . $label . '</td>
								<td class="c-descr" onclick="' . htmlspecialchars($onClickCell) . '">' . (empty($help) ? '' : $help) . '</td>
						</tr>';
					$c++;
				}
			}
		}
		// Remaining values (invalid):
		if (count($itemArray) && !$PA['fieldTSConfig']['disableNoMatchingValueElement'] && !$config['disableNoMatchingValueElement']) {
			foreach ($itemArray as $theNoMatchValue => $temp) {
				// Compile <checkboxes> tag:
				array_unshift($tRows, '
						<tr class="c-invalidItem">
							<td class="c-checkbox"><input type="checkbox"' . $this->insertDefStyle('check')
					. ' name="' . htmlspecialchars(($PA['itemFormElName'] . '[' . $c . ']'))
					. '" value="' . htmlspecialchars($theNoMatchValue) . '" checked="checked" onclick="' . htmlspecialchars($sOnChange) . '"'
					. $PA['onFocus'] . $disabled . ' /></td>
							<td class="c-labelCell">' . htmlspecialchars(@sprintf($nMV_label, $theNoMatchValue), ENT_COMPAT, 'UTF-8', FALSE) . '</td><td>&nbsp;</td>
						</tr>');
				$c++;
			}
		}
		// Add an empty hidden field which will send a blank value if all items are unselected.
		$item .= '<input type="hidden" class="select-checkbox" name="' . htmlspecialchars($PA['itemFormElName']) . '" value="" />';
		// Remaining checkboxes will get their set-all link:
		$tableHead = '';
		if (count($setAll)) {
			$tableHead = '<thead>
					<tr class="c-header-checkbox-controls t3-row-header">
						<td class="c-checkbox">
						<input type="checkbox" class="checkbox" onclick="if (checked) {' . htmlspecialchars(implode('', $setAll) . '} else {' . implode('', $unSetAll) . '}') . '">
						</td>
						<td colspan="2">
						</td>
					</tr></thead>';
		}
		// Implode rows in table:
		$item .= '
			<table border="0" cellpadding="0" cellspacing="0" class="typo3-TCEforms-select-checkbox">' . $tableHead . '<tbody>' . implode('', $tRows) . '</tbody>
			</table>
			';
		// Add revert icon
		if (!empty($restoreCmd)) {
			$item .= '<a href="#" onclick="' . implode('', $restoreCmd) . ' return false;' . '">'
				. IconUtility::getSpriteIcon('actions-edit-undo', array('title' => htmlspecialchars($this->getLL('l_revertSelection')))) . '</a>';
		}
		return $item;
	}

	/**
	 * Creates a selectorbox list (renderMode = "singlebox")
	 * (Render function for getSingleField_typeSelect())
	 *
	 * @param string $table See getSingleField_typeSelect()
	 * @param string $field See getSingleField_typeSelect()
	 * @param array $row See getSingleField_typeSelect()
	 * @param array $PA See getSingleField_typeSelect()
	 * @param array $config (Redundant) content of $PA['fieldConf']['config'] (for convenience)
	 * @param array $selItems Items available for selection
	 * @param string $nMV_label Label for no-matching-value
	 * @return string The HTML code for the item
	 * @see getSingleField_typeSelect()
	 * @todo Define visibility
	 */
	public function getSingleField_typeSelect_singlebox($table, $field, $row, &$PA, $config, $selItems, $nMV_label) {
		// Get values in an array (and make unique, which is fine because there can be no duplicates anyway):
		$itemArray = array_flip($this->extractValuesOnlyFromValueLabelList($PA['itemFormElValue']));
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		// Traverse the Array of selector box items:
		$opt = array();
		// Used to accumulate the JS needed to restore the original selection.
		$restoreCmd = array();
		$c = 0;
		foreach ($selItems as $p) {
			// Selected or not by default:
			$sM = '';
			if (isset($itemArray[$p[1]])) {
				$sM = ' selected="selected"';
				$restoreCmd[] = $this->elName(($PA['itemFormElName'] . '[]')) . '.options[' . $c . '].selected=1;';
				unset($itemArray[$p[1]]);
			}
			// Non-selectable element:
			$nonSel = '';
			if ((string) $p[1] === '--div--') {
				$nonSel = ' onclick="this.selected=0;" class="c-divider"';
			}
			// Icon style for option tag:
			$styleAttrValue = '';
			if ($config['iconsInOptionTags']) {
				$styleAttrValue = $this->optionTagStyle($p[2]);
			}
			// Compile <option> tag:
			$opt[] = '<option value="' . htmlspecialchars($p[1]) . '"' . $sM . $nonSel
				. ($styleAttrValue ? ' style="' . htmlspecialchars($styleAttrValue) . '"' : '') . '>'
				. htmlspecialchars($p[0], ENT_COMPAT, 'UTF-8', FALSE) . '</option>';
			$c++;
		}
		// Remaining values:
		if (count($itemArray) && !$PA['fieldTSConfig']['disableNoMatchingValueElement'] && !$config['disableNoMatchingValueElement']) {
			foreach ($itemArray as $theNoMatchValue => $temp) {
				// Compile <option> tag:
				array_unshift($opt, '<option value="' . htmlspecialchars($theNoMatchValue) . '" selected="selected">'
					. htmlspecialchars(@sprintf($nMV_label, $theNoMatchValue), ENT_COMPAT, 'UTF-8', FALSE) . '</option>');
			}
		}
		// Compile selector box:
		$sOnChange = implode('', $PA['fieldChangeFunc']);
		$selector_itemListStyle = isset($config['itemListStyle'])
			? ' style="' . htmlspecialchars($config['itemListStyle']) . '"'
			: ' style="' . $this->defaultMultipleSelectorStyle . '"';
		$size = (int)$config['size'];
		$cssPrefix = $size === 1 ? 'tceforms-select' : 'tceforms-multiselect';
		$size = $config['autoSizeMax']
			? MathUtility::forceIntegerInRange(count($selItems) + 1, MathUtility::forceIntegerInRange($size, 1), $config['autoSizeMax'])
			: $size;
		$selectBox = '<select id="' . uniqid($cssPrefix) . '" name="' . $PA['itemFormElName'] . '[]"'
			. $this->insertDefStyle('select', $cssPrefix) . ($size ? ' size="' . $size . '"' : '')
			. ' multiple="multiple" onchange="' . htmlspecialchars($sOnChange) . '"' . $PA['onFocus']
			. $selector_itemListStyle . $disabled . '>
						' . implode('
						', $opt) . '
					</select>';
		// Add an empty hidden field which will send a blank value if all items are unselected.
		if (!$disabled) {
			$item .= '<input type="hidden" name="' . htmlspecialchars($PA['itemFormElName']) . '" value="" />';
		}
		// Put it all into a table:
		$onClick = htmlspecialchars($this->elName(($PA['itemFormElName'] . '[]')) . '.selectedIndex=-1;' . implode('', $restoreCmd) . ' return false;');
		$item .= '
			<table border="0" cellspacing="0" cellpadding="0" width="1" class="typo3-TCEforms-select-singlebox">
				<tr>
					<td>
					' . $selectBox . '
					<br/>
					<em>' . htmlspecialchars($this->getLL('l_holdDownCTRL')) . '</em>
					</td>
					<td valign="top">
						<a href="#" onclick="' . $onClick . '" title="' . htmlspecialchars($this->getLL('l_revertSelection')) . '">'
			. IconUtility::getSpriteIcon('actions-edit-undo') . '</a>
					</td>
				</tr>
			</table>
				';
		return $item;
	}

	/**
	 * Creates a multiple-selector box (two boxes, side-by-side)
	 * (Render function for getSingleField_typeSelect())
	 *
	 * @param string $table See getSingleField_typeSelect()
	 * @param string $field See getSingleField_typeSelect()
	 * @param array $row See getSingleField_typeSelect()
	 * @param array $PA See getSingleField_typeSelect()
	 * @param array $config (Redundant) content of $PA['fieldConf']['config'] (for convenience)
	 * @param array $selItems Items available for selection
	 * @param string $nMV_label Label for no-matching-value
	 * @return string The HTML code for the item
	 * @see getSingleField_typeSelect()
	 * @todo Define visibility
	 */
	public function getSingleField_typeSelect_multiple($table, $field, $row, &$PA, $config, $selItems, $nMV_label) {
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		// Setting this hidden field (as a flag that JavaScript can read out)
		if (!$disabled) {
			$item .= '<input type="hidden" name="' . $PA['itemFormElName'] . '_mul" value="' . ($config['multiple'] ? 1 : 0) . '" />';
		}
		// Set max and min items:
		$maxitems = MathUtility::forceIntegerInRange($config['maxitems'], 0);
		if (!$maxitems) {
			$maxitems = 100000;
		}
		$minitems = MathUtility::forceIntegerInRange($config['minitems'], 0);
		// Register the required number of elements:
		$this->registerRequiredProperty('range', $PA['itemFormElName'], array($minitems, $maxitems, 'imgName' => $table . '_' . $row['uid'] . '_' . $field));
		// Get "removeItems":
		$removeItems = GeneralUtility::trimExplode(',', $PA['fieldTSConfig']['removeItems'], TRUE);
		// Get the array with selected items:
		$itemArray = GeneralUtility::trimExplode(',', $PA['itemFormElValue'], TRUE);

		// Possibly filter some items:
		$itemArray = GeneralUtility::keepItemsInArray(
			$itemArray,
			$PA['fieldTSConfig']['keepItems'],
			function ($value) {
				$parts = explode('|', $value, 2);
				return rawurldecode($parts[0]);
			}
		);

		// Perform modification of the selected items array:
		foreach ($itemArray as $tk => $tv) {
			$tvP = explode('|', $tv, 2);
			$evalValue = $tvP[0];
			$isRemoved = in_array($evalValue, $removeItems)
				|| $config['form_type'] == 'select' && $config['authMode']
				&& !$this->getBackendUserAuthentication()->checkAuthMode($table, $field, $evalValue, $config['authMode']);
			if ($isRemoved && !$PA['fieldTSConfig']['disableNoMatchingValueElement'] && !$config['disableNoMatchingValueElement']) {
				$tvP[1] = rawurlencode(@sprintf($nMV_label, $evalValue));
			} elseif (isset($PA['fieldTSConfig']['altLabels.'][$evalValue])) {
				$tvP[1] = rawurlencode($this->sL($PA['fieldTSConfig']['altLabels.'][$evalValue]));
			}
			if ($tvP[1] == '') {
				// Case: flexform, default values supplied, no label provided (bug #9795)
				foreach ($selItems as $selItem) {
					if ($selItem[1] == $tvP[0]) {
						$tvP[1] = html_entity_decode($selItem[0]);
						break;
					}
				}
			}
			$itemArray[$tk] = implode('|', $tvP);
		}
		$itemsToSelect = '';
		$filterTextfield = '';
		$filterSelectbox = '';
		$size = 0;
		if (!$disabled) {
			// Create option tags:
			$opt = array();
			$styleAttrValue = '';
			foreach ($selItems as $p) {
				if ($config['iconsInOptionTags']) {
					$styleAttrValue = $this->optionTagStyle($p[2]);
				}
				$opt[] = '<option value="' . htmlspecialchars($p[1]) . '"'
					. ($styleAttrValue ? ' style="' . htmlspecialchars($styleAttrValue) . '"' : '')
					. ' title="' . $p[0] . '">' . $p[0] . '</option>';
			}
			// Put together the selector box:
			$selector_itemListStyle = isset($config['itemListStyle'])
				? ' style="' . htmlspecialchars($config['itemListStyle']) . '"'
				: ' style="' . $this->defaultMultipleSelectorStyle . '"';
			$size = (int)$config['size'];
			$size = $config['autoSizeMax']
				? MathUtility::forceIntegerInRange(count($itemArray) + 1, MathUtility::forceIntegerInRange($size, 1), $config['autoSizeMax'])
				: $size;
			$sOnChange = implode('', $PA['fieldChangeFunc']);

			$multiSelectId = uniqid('tceforms-multiselect-');
			$itemsToSelect = '
				<select data-relatedfieldname="' . htmlspecialchars($PA['itemFormElName']) . '" data-exclusivevalues="'
				. htmlspecialchars($config['exclusiveKeys']) . '" id="' . $multiSelectId . '" name="' . $PA['itemFormElName'] . '_sel"'
				. $this->insertDefStyle('select', 'tceforms-multiselect tceforms-itemstoselect t3-form-select-itemstoselect')
				. ($size ? ' size="' . $size . '"' : '') . ' onchange="' . htmlspecialchars($sOnChange) . '"'
				. $PA['onFocus'] . $selector_itemListStyle . '>
					' . implode('
					', $opt) . '
				</select>';

			if ($config['enableMultiSelectFilterTextfield'] || $config['multiSelectFilterItems']) {
				$this->multiSelectFilterCount++;
				$jsSelectBoxFilterName = str_replace(' ', '', ucwords(str_replace('-', ' ', GeneralUtility::strtolower($multiSelectId))));
				$this->additionalJS_post[] = '
					var ' . $jsSelectBoxFilterName . ' = new TCEForms.SelectBoxFilter("' . $multiSelectId . '");
				';
			}

			if ($config['enableMultiSelectFilterTextfield']) {
				// add input field for filter
				$filterTextfield = '<input class="typo3-TCEforms-suggest-search typo3-TCEforms-multiselect-filter" id="'
					. $multiSelectId . '_filtertextfield" value="" style="width: 104px;" />';
			}

			if (isset($config['multiSelectFilterItems']) && is_array($config['multiSelectFilterItems']) && count($config['multiSelectFilterItems']) > 1) {
				$filterDropDownOptions = array();
				foreach ($config['multiSelectFilterItems'] as $optionElement) {
					$optionValue = $this->sL(isset($optionElement[1]) && $optionElement[1] != '' ? $optionElement[1]
						: $optionElement[0]);
					$filterDropDownOptions[] = '<option value="' . htmlspecialchars($this->sL($optionElement[0])) . '">'
						. htmlspecialchars($optionValue) . '</option>';
				}
				$filterSelectbox = '
					<select id="' . $multiSelectId . '_filterdropdown">
						' . implode('
						', $filterDropDownOptions) . '
					</select>';
			}
		}
		// Pass to "dbFileIcons" function:
		$params = array(
			'size' => $size,
			'autoSizeMax' => MathUtility::forceIntegerInRange($config['autoSizeMax'], 0),
			'style' => isset($config['selectedListStyle'])
					? ' style="' . htmlspecialchars($config['selectedListStyle']) . '"'
					: ' style="' . $this->defaultMultipleSelectorStyle . '"',
			'dontShowMoveIcons' => $maxitems <= 1,
			'maxitems' => $maxitems,
			'info' => '',
			'headers' => array(
				'selector' => $this->getLL('l_selected') . ':<br />',
				'items' => $this->getLL('l_items') . ': ' . $filterSelectbox . $filterTextfield . '<br />'
			),
			'noBrowser' => 1,
			'thumbnails' => $itemsToSelect,
			'readOnly' => $disabled
		);
		$item .= $this->dbFileIcons($PA['itemFormElName'], '', '', $itemArray, '', $params, $PA['onFocus']);
		return $item;
	}

	/**
	 * Generation of TCEform elements of the type "group"
	 * This will render a selectorbox into which elements from either the file system or database can be inserted. Relations.
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeGroup($table, $field, $row, &$PA) {
		// Init:
		$config = $PA['fieldConf']['config'];
		$show_thumbs = $config['show_thumbs'];
		$size = isset($config['size']) ? (int)$config['size'] : 5;
		$maxitems = MathUtility::forceIntegerInRange($config['maxitems'], 0);
		if (!$maxitems) {
			$maxitems = 100000;
		}
		$minitems = MathUtility::forceIntegerInRange($config['minitems'], 0);
		$allowed = trim($config['allowed']);
		$disallowed = trim($config['disallowed']);
		$item = '';
		$disabled = '';
		if ($this->renderReadonly || $config['readOnly']) {
			$disabled = ' disabled="disabled"';
		}
		$item .= '<input type="hidden" name="' . $PA['itemFormElName'] . '_mul" value="' . ($config['multiple'] ? 1 : 0) . '"' . $disabled . ' />';
		$this->registerRequiredProperty('range', $PA['itemFormElName'], array($minitems, $maxitems, 'imgName' => $table . '_' . $row['uid'] . '_' . $field));
		$info = '';
		// "Extra" configuration; Returns configuration for the field based on settings found in the "types" fieldlist.
		$specConf = $this->getSpecConfFromString($PA['extra'], $PA['fieldConf']['defaultExtras']);
		$PA['itemFormElID_file'] = $PA['itemFormElID'] . '_files';
		// whether the list and delete controls should be disabled
		$noList = isset($config['disable_controls']) && GeneralUtility::inList($config['disable_controls'], 'list');
		$noDelete = isset($config['disable_controls']) && GeneralUtility::inList($config['disable_controls'], 'delete');
		// if maxitems==1 then automatically replace the current item (in list and file selector)
		if ($maxitems === 1) {
			$this->additionalJS_post[] = 'TBE_EDITOR.clearBeforeSettingFormValueFromBrowseWin[\'' . $PA['itemFormElName'] . '\'] = {
					itemFormElID_file: \'' . $PA['itemFormElID_file'] . '\'
				}';
			$PA['fieldChangeFunc']['TBE_EDITOR_fieldChanged'] = 'setFormValueManipulate(\'' . $PA['itemFormElName']
				. '\', \'Remove\'); ' . $PA['fieldChangeFunc']['TBE_EDITOR_fieldChanged'];
		} elseif ($noList) {
			// If the list controls have been removed and the maximum number is reached, remove the first entry to avoid "write once" field
			$PA['fieldChangeFunc']['TBE_EDITOR_fieldChanged'] = 'setFormValueManipulate(\'' . $PA['itemFormElName']
				. '\', \'RemoveFirstIfFull\', \'' . $maxitems . '\'); ' . $PA['fieldChangeFunc']['TBE_EDITOR_fieldChanged'];
		}
		// Acting according to either "file" or "db" type:
		switch ((string) $config['internal_type']) {
		case 'file_reference':
			$config['uploadfolder'] = '';
			// Fall through
		case 'file':
			// Creating string showing allowed types:
			$tempFT = GeneralUtility::trimExplode(',', $allowed, TRUE);
			if (!count($tempFT)) {
				$info .= '*';
			}
			foreach ($tempFT as $ext) {
				if ($ext) {
					$info .= strtoupper($ext) . ' ';
				}
			}
			// Creating string, showing disallowed types:
			$tempFT_dis = GeneralUtility::trimExplode(',', $disallowed, TRUE);
			if (count($tempFT_dis)) {
				$info .= '<br />';
			}
			foreach ($tempFT_dis as $ext) {
				if ($ext) {
					$info .= '-' . strtoupper($ext) . ' ';
				}
			}
			// Making the array of file items:
			$itemArray = GeneralUtility::trimExplode(',', $PA['itemFormElValue'], TRUE);
			$fileFactory = ResourceFactory::getInstance();
			// Correct the filename for the FAL items
			foreach ($itemArray as &$fileItem) {
				list($fileUid, $fileLabel) = explode('|', $fileItem);
				if (MathUtility::canBeInterpretedAsInteger($fileUid)) {
					$fileObject = $fileFactory->getFileObject($fileUid);
					$fileLabel = $fileObject->getName();
				}
				$fileItem = $fileUid . '|' . $fileLabel;
			}
			// Showing thumbnails:
			$thumbsnail = '';
			if ($show_thumbs) {
				$imgs = array();
				foreach ($itemArray as $imgRead) {
					$imgP = explode('|', $imgRead);
					$imgPath = rawurldecode($imgP[0]);
					// FAL icon production
					if (MathUtility::canBeInterpretedAsInteger($imgP[0])) {
						$fileObject = $fileFactory->getFileObject($imgP[0]);

						if ($fileObject->isMissing()) {
							$flashMessage = \TYPO3\CMS\Core\Resource\Utility\BackendUtility::getFlashMessageForMissingFile($fileObject);
							$imgs[] = $flashMessage->render();
						} elseif (GeneralUtility::inList($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'], $fileObject->getExtension())) {
							$imageUrl = $fileObject->process(ProcessedFile::CONTEXT_IMAGEPREVIEW, array())->getPublicUrl(TRUE);
							$imgTag = '<img src="' . $imageUrl . '" alt="' . htmlspecialchars($fileObject->getName()) . '" />';
							$imgs[] = '<span class="nobr">' . $imgTag . htmlspecialchars($fileObject->getName()) . '</span>';
						} else {
							// Icon
							$imgTag = IconUtility::getSpriteIconForResource($fileObject, array('title' => $fileObject->getName()));
							$imgs[] = '<span class="nobr">' . $imgTag . htmlspecialchars($fileObject->getName()) . '</span>';
						}
					} else {
						$rowCopy = array();
						$rowCopy[$field] = $imgPath;
						$thumbnailCode = '';
						try {
							$thumbnailCode = BackendUtility::thumbCode(
								$rowCopy, $table, $field, $this->backPath, 'thumbs.php',
								$config['uploadfolder'], 0, ' align="middle"'
							);
							$thumbnailCode = '<span class="nobr">' . $thumbnailCode . $imgPath . '</span>';

						} catch (\Exception $exception) {
							/** @var $flashMessage FlashMessage */
							$message = $exception->getMessage();
							$flashMessage = GeneralUtility::makeInstance(
								'TYPO3\\CMS\\Core\\Messaging\\FlashMessage',
								htmlspecialchars($message), '', FlashMessage::ERROR, TRUE
							);
							$class = 'TYPO3\\CMS\\Core\\Messaging\\FlashMessageService';
							/** @var $flashMessageService \TYPO3\CMS\Core\Messaging\FlashMessageService */
							$flashMessageService = GeneralUtility::makeInstance($class);
							$defaultFlashMessageQueue = $flashMessageService->getMessageQueueByIdentifier();
							$defaultFlashMessageQueue->enqueue($flashMessage);

							$logMessage = $message . ' (' . $table . ':' . $row['uid'] . ')';
							GeneralUtility::sysLog($logMessage, 'core', GeneralUtility::SYSLOG_SEVERITY_WARNING);
						}

						$imgs[] = $thumbnailCode;
					}
				}
				$thumbsnail = implode('<br />', $imgs);
			}
			// Creating the element:
			$params = array(
				'size' => $size,
				'dontShowMoveIcons' => $maxitems <= 1,
				'autoSizeMax' => MathUtility::forceIntegerInRange($config['autoSizeMax'], 0),
				'maxitems' => $maxitems,
				'style' => isset($config['selectedListStyle'])
						? ' style="' . htmlspecialchars($config['selectedListStyle']) . '"'
						: ' style="' . $this->defaultMultipleSelectorStyle . '"',
				'info' => $info,
				'thumbnails' => $thumbsnail,
				'readOnly' => $disabled,
				'noBrowser' => $noList || isset($config['disable_controls']) && GeneralUtility::inList($config['disable_controls'], 'browser'),
				'noList' => $noList,
				'noDelete' => $noDelete
			);
			$item .= $this->dbFileIcons($PA['itemFormElName'], 'file', implode(',', $tempFT), $itemArray, '', $params, $PA['onFocus'], '', '', '', $config);
			if (!$disabled && !(isset($config['disable_controls']) && GeneralUtility::inList($config['disable_controls'], 'upload'))) {
				// Adding the upload field:
				if ($this->edit_docModuleUpload && $config['uploadfolder']) {
					// Insert the multiple attribute to enable HTML5 multiple file upload
					$multipleAttribute = '';
					$multipleFilenameSuffix = '';
					if (isset($config['maxitems']) && $config['maxitems'] > 1) {
						$multipleAttribute = ' multiple="multiple"';
						$multipleFilenameSuffix = '[]';
					}
					$item .= '<div id="' . $PA['itemFormElID_file'] . '"><input type="file"' . $multipleAttribute
						. ' name="' . $PA['itemFormElName_file'] . $multipleFilenameSuffix . '" size="35" onchange="'
						. implode('', $PA['fieldChangeFunc']) . '" /></div>';
				}
			}
			break;
		case 'folder':
			// If the element is of the internal type "folder":
			// Array of folder items:
			$itemArray = GeneralUtility::trimExplode(',', $PA['itemFormElValue'], TRUE);
			// Creating the element:
			$params = array(
				'size' => $size,
				'dontShowMoveIcons' => $maxitems <= 1,
				'autoSizeMax' => MathUtility::forceIntegerInRange($config['autoSizeMax'], 0),
				'maxitems' => $maxitems,
				'style' => isset($config['selectedListStyle'])
						? ' style="' . htmlspecialchars($config['selectedListStyle']) . '"'
						: ' style="' . $this->defaultMultipleSelectorStyle . '"',
				'info' => $info,
				'readOnly' => $disabled,
				'noBrowser' => $noList || isset($config['disable_controls']) && GeneralUtility::inList($config['disable_controls'], 'browser'),
				'noList' => $noList
			);
			$item .= $this->dbFileIcons($PA['itemFormElName'], 'folder', '', $itemArray, '', $params, $PA['onFocus']);
			break;
		case 'db':
			// If the element is of the internal type "db":
			// Creating string showing allowed types:
			$tempFT = GeneralUtility::trimExplode(',', $allowed, TRUE);
			$onlySingleTableAllowed = FALSE;
			if (trim($tempFT[0]) === '*') {
				$info .= '<span class="nobr">' . htmlspecialchars($this->getLL('l_allTables')) . '</span><br />';
			} elseif ($tempFT) {
				$onlySingleTableAllowed = count($tempFT) == 1;
				foreach ($tempFT as $theT) {
					$aOnClick = 'setFormValueOpenBrowser(\'db\', \'' . ($PA['itemFormElName'] . '|||' . $theT) . '\'); return false;';
					$info .= '<span class="nobr">
									<a href="#" onclick="' . htmlspecialchars($aOnClick) . '">'
						. IconUtility::getSpriteIconForRecord($theT, array())
						. htmlspecialchars($this->sL($GLOBALS['TCA'][$theT]['ctrl']['title'])) . '</a></span><br />';
				}
			}
			$perms_clause = $this->getBackendUserAuthentication()->getPagePermsClause(1);
			$itemArray = array();
			$imgs = array();
			// Thumbnails:
			$temp_itemArray = GeneralUtility::trimExplode(',', $PA['itemFormElValue'], TRUE);
			foreach ($temp_itemArray as $dbRead) {
				$recordParts = explode('|', $dbRead);
				list($this_table, $this_uid) = BackendUtility::splitTable_Uid($recordParts[0]);
				// For the case that no table was found and only a single table is defined to be allowed, use that one:
				if (!$this_table && $onlySingleTableAllowed) {
					$this_table = $allowed;
				}
				$itemArray[] = array('table' => $this_table, 'id' => $this_uid);
				if (!$disabled && $show_thumbs) {
					$rr = BackendUtility::getRecordWSOL($this_table, $this_uid);
					$imgs[] = '<span class="nobr">' . $this->getClickMenu(IconUtility::getSpriteIconForRecord($this_table, $rr, array(
						'style' => 'vertical-align:top',
						'title' => htmlspecialchars((BackendUtility::getRecordPath($rr['pid'], $perms_clause, 15) . ' [UID: ' . $rr['uid'] . ']'))
					)), $this_table, $this_uid) . '&nbsp;' . BackendUtility::getRecordTitle($this_table, $rr, TRUE)
						. ' <span class="typo3-dimmed"><em>[' . $rr['uid'] . ']</em></span>' . '</span>';
				}
			}
			$thumbsnail = '';
			if (!$disabled && $show_thumbs) {
				$thumbsnail = implode('<br />', $imgs);
			}
			// Creating the element:
			$params = array(
				'size' => $size,
				'dontShowMoveIcons' => $maxitems <= 1,
				'autoSizeMax' => MathUtility::forceIntegerInRange($config['autoSizeMax'], 0),
				'maxitems' => $maxitems,
				'style' => isset($config['selectedListStyle'])
						? ' style="' . htmlspecialchars($config['selectedListStyle']) . '"'
						: ' style="' . $this->defaultMultipleSelectorStyle . '"',
				'info' => $info,
				'thumbnails' => $thumbsnail,
				'readOnly' => $disabled,
				'noBrowser' => $noList || isset($config['disable_controls']) && GeneralUtility::inList($config['disable_controls'], 'browser'),
				'noList' => $noList
			);
			$item .= $this->dbFileIcons($PA['itemFormElName'], 'db', implode(',', $tempFT), $itemArray, '', $params, $PA['onFocus'], $table, $field, $row['uid'], $config);
			break;
		}
		// Wizards:
		$altItem = '<input type="hidden" name="' . $PA['itemFormElName'] . '" value="' . htmlspecialchars($PA['itemFormElValue']) . '" />';
		if (!$disabled) {
			$item = $this->renderWizards(array($item, $altItem), $config['wizards'], $table, $row, $field, $PA, $PA['itemFormElName'], $specConf);
		}
		return $item;
	}

	/**
	 * HTML rendering of a value which is not editable.
	 *
	 * @param array $config Configuration for the display
	 * @param string $itemValue The value to display
	 * @return string The HTML code for the display
	 * @see getSingleField_typeNone();
	 * @todo Define visibility
	 */
	public function getSingleField_typeNone_render($config, $itemValue) {
		if ($config['format']) {
			$itemValue = $this->formatValue($config, $itemValue);
		}
		$rows = (int)$config['rows'];
		if ($rows > 1) {
			if (!$config['pass_content']) {
				$itemValue = nl2br(htmlspecialchars($itemValue));
			}
			// Like textarea
			$cols = MathUtility::forceIntegerInRange($config['cols'] ? $config['cols'] : 30, 5, $this->maxTextareaWidth);
			if (!$config['fixedRows']) {
				$origRows = ($rows = MathUtility::forceIntegerInRange($rows, 1, 20));
				if (strlen($itemValue) > $this->charsPerRow * 2) {
					$cols = $this->maxTextareaWidth;
					$rows = MathUtility::forceIntegerInRange(round(strlen($itemValue) / $this->charsPerRow), count(explode(LF, $itemValue)), 20);
					if ($rows < $origRows) {
						$rows = $origRows;
					}
				}
			}

			$cols = round($cols * $this->form_largeComp);
			$width = ceil($cols * $this->form_rowsToStylewidth);
			// Hardcoded: 12 is the height of the font
			$height = $rows * 12;
			$item = '
				<div style="overflow:auto; height:' . $height . 'px; width:' . $width . 'px;" class="t3-tceforms-fieldReadOnly">'
				. $itemValue . IconUtility::getSpriteIcon('status-status-readonly') . '</div>';
		} else {
			if (!$config['pass_content']) {
				$itemValue = htmlspecialchars($itemValue);
			}
			$cols = $config['cols'] ? $config['cols'] : ($config['size'] ? $config['size'] : $this->maxInputWidth);
			$cols = round($cols * $this->form_largeComp);
			$width = ceil($cols * $this->form_rowsToStylewidth);
			// Overflow:auto crashes mozilla here. Title tag is useful when text is longer than the div box (overflow:hidden).
			$item = '
				<div style="overflow:hidden; width:' . $width . 'px;" class="t3-tceforms-fieldReadOnly" title="' . $itemValue . '">'
				. '<span class="nobr">' . ((string)$itemValue !== '' ? $itemValue : '&nbsp;') . '</span>'
				. IconUtility::getSpriteIcon('status-status-readonly') . '</div>';
		}
		return $item;
	}

	/**
	 * Handler for Flex Forms
	 *
	 * @param string $table The table name of the record
	 * @param string $field The field name which this element is supposed to edit
	 * @param array $row The record data array where the value(s) for the field can be found
	 * @param array $PA An array with additional configuration options.
	 * @return string The HTML code for the TCEform field
	 * @todo Define visibility
	 */
	public function getSingleField_typeFlex($table, $field, $row, &$PA) {
		// Data Structure:
		$dataStructArray = BackendUtility::getFlexFormDS($PA['fieldConf']['config'], $row, $table, $field);
		$item = '';
		// Manipulate Flexform DS via TSConfig and group access lists
		if (is_array($dataStructArray)) {
			$flexFormHelper = GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Form\\FlexFormsHelper');
			$dataStructArray = $flexFormHelper->modifyFlexFormDS($dataStructArray, $table, $field, $row, $PA['fieldConf']);
			unset($flexFormHelper);
		}
		// Get data structure:
		if (is_array($dataStructArray)) {
			// Get data:
			$xmlData = $PA['itemFormElValue'];
			$xmlHeaderAttributes = GeneralUtility::xmlGetHeaderAttribs($xmlData);
			$storeInCharset = strtolower($xmlHeaderAttributes['encoding']);
			if ($storeInCharset) {
				$currentCharset = $this->getLanguageService()->charSet;
				$xmlData = $this->getLanguageService()->csConvObj->conv($xmlData, $storeInCharset, $currentCharset, 1);
			}
			$editData = GeneralUtility::xml2array($xmlData);
			// Must be XML parsing error...
			if (!is_array($editData)) {
				$editData = array();
			} elseif (!isset($editData['meta']) || !is_array($editData['meta'])) {
				$editData['meta'] = array();
			}
			// Find the data structure if sheets are found:
			$sheet = $editData['meta']['currentSheetId'] ? $editData['meta']['currentSheetId'] : 'sDEF';
			// Sheet to display
			// Create language menu:
			$langChildren = $dataStructArray['meta']['langChildren'] ? 1 : 0;
			$langDisabled = $dataStructArray['meta']['langDisable'] ? 1 : 0;
			$editData['meta']['currentLangId'] = array();
			// Look up page overlays:
			$checkPageLanguageOverlay = $this->getBackendUserAuthentication()->getTSConfigVal('options.checkPageLanguageOverlay') ? TRUE : FALSE;
			if ($checkPageLanguageOverlay) {
				$where_clause = 'pid=' . (int)$row['pid'] . BackendUtility::deleteClause('pages_language_overlay')
					. BackendUtility::versioningPlaceholderClause('pages_language_overlay');
				$pageOverlays = $this->getDatabaseConnection()->exec_SELECTgetRows('*', 'pages_language_overlay', $where_clause, '', '', '', 'sys_language_uid');
			}
			$languages = $this->getAvailableLanguages();
			foreach ($languages as $lInfo) {
				if (
					$this->getBackendUserAuthentication()->checkLanguageAccess($lInfo['uid'])
					&& (!$checkPageLanguageOverlay || $lInfo['uid'] <= 0 || is_array($pageOverlays[$lInfo['uid']]))
				) {
					$editData['meta']['currentLangId'][] = $lInfo['ISOcode'];
				}
			}
			if (!is_array($editData['meta']['currentLangId']) || !count($editData['meta']['currentLangId'])) {
				$editData['meta']['currentLangId'] = array('DEF');
			}
			$editData['meta']['currentLangId'] = array_unique($editData['meta']['currentLangId']);
			$PA['_noEditDEF'] = FALSE;
			if ($langChildren || $langDisabled) {
				$rotateLang = array('DEF');
			} else {
				if (!in_array('DEF', $editData['meta']['currentLangId'])) {
					array_unshift($editData['meta']['currentLangId'], 'DEF');
					$PA['_noEditDEF'] = TRUE;
				}
				$rotateLang = $editData['meta']['currentLangId'];
			}
			// Tabs sheets
			if (is_array($dataStructArray['sheets'])) {
				$tabsToTraverse = array_keys($dataStructArray['sheets']);
			} else {
				$tabsToTraverse = array($sheet);
			}

			/** @var $elementConditionMatcher \TYPO3\CMS\Backend\Form\ElementConditionMatcher */
			$elementConditionMatcher = GeneralUtility::makeInstance('TYPO3\\CMS\\Backend\\Form\\ElementConditionMatcher');

			foreach ($rotateLang as $lKey) {
				if (!$langChildren && !$langDisabled) {
					$item .= '<strong>' . $this->getLanguageIcon($table, $row, ('v' . $lKey)) . $lKey . ':</strong>';
				}
				// Default language, other options are "lUK" or whatever country code (independent of system!!!)
				$lang = 'l' . $lKey;
				$tabParts = array();
				$sheetContent = '';
				foreach ($tabsToTraverse as $sheet) {
					list($dataStruct, $sheet) = GeneralUtility::resolveSheetDefInDS($dataStructArray, $sheet);
					// If sheet has displayCond
					if ($dataStruct['ROOT']['TCEforms']['displayCond']) {
						$splitCondition = GeneralUtility::trimExplode(':', $dataStruct['ROOT']['TCEforms']['displayCond']);
						$skipCondition = FALSE;
						$fakeRow = array();
						switch ($splitCondition[0]) {
							case 'FIELD':
								list($sheetName, $fieldName) = GeneralUtility::trimExplode('.', $splitCondition[1]);
								$fieldValue = $editData['data'][$sheetName][$lang][$fieldName];
								$splitCondition[1] = $fieldName;
								$dataStruct['ROOT']['TCEforms']['displayCond'] = join(':', $splitCondition);
								$fakeRow = array($fieldName => $fieldValue);
								break;
							case 'HIDE_FOR_NON_ADMINS':

							case 'VERSION':

							case 'HIDE_L10N_SIBLINGS':

							case 'EXT':
								break;
							case 'REC':
								$fakeRow = array('uid' => $row['uid']);
								break;
							default:
								$skipCondition = TRUE;
						}
						$displayConditionResult = TRUE;
						if ($dataStruct['ROOT']['TCEforms']['displayCond']) {
							$displayConditionResult = $elementConditionMatcher->match($dataStruct['ROOT']['TCEforms']['displayCond'], $fakeRow, 'vDEF');
						}
						// If sheets displayCond leads to false
						if (!$skipCondition && !$displayConditionResult) {
							// Don't create this sheet
							continue;
						}
					}
					// Render sheet:
					if (is_array($dataStruct['ROOT']) && is_array($dataStruct['ROOT']['el'])) {
						// Default language, other options are "lUK" or whatever country code (independent of system!!!)
						$PA['_valLang'] = $langChildren && !$langDisabled ? $editData['meta']['currentLangId'] : 'DEF';
						$PA['_lang'] = $lang;
						// Assemble key for loading the correct CSH file
						$dsPointerFields = GeneralUtility::trimExplode(',', $GLOBALS['TCA'][$table]['columns'][$field]['config']['ds_pointerField'], TRUE);
						$PA['_cshKey'] = $table . '.' . $field;
						foreach ($dsPointerFields as $key) {
							$PA['_cshKey'] .= '.' . $row[$key];
						}
						// Push the sheet level tab to DynNestedStack
						$tabIdentString = '';
						if (is_array($dataStructArray['sheets'])) {
							$tabIdentString = $this->getDocumentTemplate()->getDynTabMenuId('TCEFORMS:flexform:' . $PA['itemFormElName'] . $PA['_lang']);
							$this->pushToDynNestedStack('tab', $tabIdentString . '-' . (count($tabParts) + 1));
						}
						// Render flexform:
						$tRows = $this->getSingleField_typeFlex_draw($dataStruct['ROOT']['el'], $editData['data'][$sheet][$lang], $table, $field, $row, $PA, '[data][' . $sheet . '][' . $lang . ']');
						$sheetContent = '<div class="typo3-TCEforms-flexForm">' . $tRows . '</div>';
						// Pop the sheet level tab from DynNestedStack
						if (is_array($dataStructArray['sheets'])) {
							$this->popFromDynNestedStack('tab', $tabIdentString . '-' . (count($tabParts) + 1));
						}
					} else {
						$sheetContent = 'Data Structure ERROR: No ROOT element found for sheet "' . $sheet . '".';
					}
					// Add to tab:
					$tabParts[] = array(
						'label' => $dataStruct['ROOT']['TCEforms']['sheetTitle'] ? $this->sL($dataStruct['ROOT']['TCEforms']['sheetTitle']) : $sheet,
						'description' => $dataStruct['ROOT']['TCEforms']['sheetDescription'] ? $this->sL($dataStruct['ROOT']['TCEforms']['sheetDescription']) : '',
						'linkTitle' => $dataStruct['ROOT']['TCEforms']['sheetShortDescr'] ? $this->sL($dataStruct['ROOT']['TCEforms']['sheetShortDescr']) : '',
						'content' => $sheetContent
					);
				}
				if (is_array($dataStructArray['sheets'])) {
					$dividersToTabsBehaviour = isset($GLOBALS['TCA'][$table]['ctrl']['dividers2tabs']) ? $GLOBALS['TCA'][$table]['ctrl']['dividers2tabs'] : 1;
					$item .= $this->getDynTabMenu($tabParts, 'TCEFORMS:flexform:' . $PA['itemFormElName'] . $PA['_lang'], $dividersToTabsBehaviour);
				} else {
					$item .= $sheetContent;
				}
			}
		} else {
			$item = 'Data Structure ERROR: ' . $dataStructArray;
		}
		return $item;
	}

	/**
	 * Creates the language menu for FlexForms:
	 *
	 * @param array $languages
	 * @param string $elName
	 * @param array $selectedLanguage
	 * @param boolean $multi
	 * @return string HTML for menu
	 * @todo Define visibility
	 */
	public function getSingleField_typeFlex_langMenu($languages, $elName, $selectedLanguage, $multi = TRUE) {
		$opt = array();
		foreach ($languages as $lArr) {
			$opt[] = '<option value="' . htmlspecialchars($lArr['ISOcode']) . '"'
				. (in_array($lArr['ISOcode'], $selectedLanguage) ? ' selected="selected"' : '') . '>'
				. htmlspecialchars($lArr['title']) . '</option>';
		}
		$output = '<select id="' . uniqid('tceforms-multiselect-')
			. ' class="tceforms-select tceforms-multiselect tceforms-flexlangmenu" name="' . $elName . '[]"'
			. ($multi ? ' multiple="multiple" size="' . count($languages) . '"' : '') . '>' . implode('', $opt)
			. '</select>';
		return $output;
	}

	/**
	 * Creates the menu for selection of the sheets:
	 *
	 * @param array $sArr Sheet array for which to render the menu
	 * @param string $elName Form element name of the field containing the sheet pointer
	 * @param string $sheetKey Current sheet key
	 * @return string HTML for menu
	 * @todo Define visibility
	 */
	public function getSingleField_typeFlex_sheetMenu($sArr, $elName, $sheetKey) {
		$tCells = array();
		$pct = round(100 / count($sArr));
		foreach ($sArr as $sKey => $sheetCfg) {
			if ($this->getBackendUserAuthentication()->jsConfirmation(1)) {
				$onClick = 'if (confirm(TBE_EDITOR.labels.onChangeAlert) && TBE_EDITOR.checkSubmit(-1)){'
					. $this->elName($elName) . '.value=\'' . $sKey . '\'; TBE_EDITOR.submitForm()};';
			} else {
				$onClick = 'if(TBE_EDITOR.checkSubmit(-1)){ ' . $this->elName($elName) . '.value=\'' . $sKey . '\'; TBE_EDITOR.submitForm();}';
			}
			$tCells[] = '<td width="' . $pct . '%" style="'
				. ($sKey == $sheetKey ? 'background-color: #9999cc; font-weight: bold;' : 'background-color: #aaaaaa;')
				. ' cursor: hand;" onclick="' . htmlspecialchars($onClick) . '" align="center">'
				. ($sheetCfg['ROOT']['TCEforms']['sheetTitle'] ? $this->sL($sheetCfg['ROOT']['TCEforms']['sheetTitle']) : $sKey)
				. '</td>';
		}
		return '<table border="0" cellpadding="0" cellspacing="2" class="typo3-TCEforms-flexForm-sheetMenu"><tr>' . implode('', $tCells) . '</tr></table>';
	}

	/********************************************
	 *
	 * Template functions
	 *
	 ********************************************/
	/**
	 * Sets the design to the backend design.
	 * Backend
	 *
	 * @return 	void
	 * @todo Define visibility
	 */
	public function setNewBEDesign() {
		$template = GeneralUtility::getUrl(PATH_site . 'typo3conf/ext/flat/Resources/Private/Templates/Backend/FormEngine.html');
		// Wrapping all table rows for a particular record being edited:
		$this->totalWrap = HtmlParser::getSubpart($template, '###TOTALWRAP###');
		// Wrapping a single field:
		$this->fieldTemplate = HtmlParser::getSubpart($template, '###FIELDTEMPLATE###');
		$this->paletteFieldTemplate = HtmlParser::getSubpart($template, '###PALETTEFIELDTEMPLATE###');
		$this->palFieldTemplate = HtmlParser::getSubpart($template, '###PALETTE_FIELDTEMPLATE###');
		$this->palFieldTemplateHeader = HtmlParser::getSubpart($template, '###PALETTE_FIELDTEMPLATE_HEADER###');
		$this->sectionWrap = HtmlParser::getSubpart($template, '###SECTION_WRAP###');
	}

}
