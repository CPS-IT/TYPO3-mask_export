<?php
namespace CPSIT\MaskExport\Aggregate;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2016 Nicole Cordes <typo3@cordes.co>, CPS-IT GmbH
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use CPSIT\MaskExport\CodeGenerator\BackendFluidCodeGenerator;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class BackendPreviewAggregate extends AbstractOverridesAggregate implements PlainTextFileAwareInterface
{
    use PlainTextFileAwareTrait;

    /**
     * @var BackendFluidCodeGenerator
     */
    protected $fluidCodeGenerator;

    /**
     * @var string
     */
    protected $templatesFilePath = 'Resources/Private/Backend/Templates/';

    /**
     * @param array $maskConfiguration
     * @param BackendFluidCodeGenerator $fluidCodeGenerator
     */
    public function __construct(array $maskConfiguration, BackendFluidCodeGenerator $fluidCodeGenerator = null)
    {
        $this->fluidCodeGenerator = (null !== $fluidCodeGenerator) ? $fluidCodeGenerator : GeneralUtility::makeInstance(BackendFluidCodeGenerator::class);
        $this->table = 'tt_content';
        parent::__construct($maskConfiguration);
    }

    /**
     * Adds PHP and Fluid files
     */
    protected function process()
    {
        $extensionConfiguration = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['mask_export']);
        if (empty($extensionConfiguration['backendPreview'])) {
            return;
        }

        if (empty($this->maskConfiguration[$this->table]['elements'])) {
            return;
        }

        $this->addDrawItemHook();
        $this->replaceTableLabels();
        $this->addFluidTemplates($this->maskConfiguration[$this->table]['elements']);
    }

    /**
     * This adds the PHP file with the hook to render own element template
     */
    protected function addDrawItemHook()
    {
        $this->appendPhpFile(
            'ext_localconf.php',
<<<EOS
// Add backend preview hook
\$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['cms/layout/class.tx_cms_layout.php']['tt_content_drawItem']['mask'] = 
    MASK\Mask\Hooks\PageLayoutViewDrawItem::class;

EOS
        );

        $contentTypes = [];
        foreach ($this->maskConfiguration[$this->table]['elements'] as $key => $element) {
            $contentTypes['mask_' . $key] = $element['columns'];
        }
        $supportedContentTypes = var_export($contentTypes, true);

        $this->addPhpFile(
            'Classes/Hooks/PageLayoutViewDrawItem.php',
<<<EOS
namespace MASK\Mask\Hooks;

use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Backend\View\PageLayoutView;
use TYPO3\CMS\Backend\View\PageLayoutViewDrawItemHookInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Fluid\View\StandaloneView;

class PageLayoutViewDrawItem implements PageLayoutViewDrawItemHookInterface
{
    /**
     * @var array
     */
    protected \$supportedContentTypes = {$supportedContentTypes};

    /**
     * Gets all files from a specific FAL-Relation
     *
     * @param int \$uid The uid of the reference row i.e. the uid of the curren tt_content row (\$data['uid'])
     * @param string \$tcaTableName The table name of the reference i.e. tt_content or pages
     * @param string \$tcaFieldName The field name of the reference i.e. "image" in tt_content or "media" in pages
     *
     * @return array
     */
    protected function getFalFiles(\$uid, \$tcaTableName = 'tt_content', \$tcaFieldName = 'tx_mask_image')
    {
        /**
         * @var \TYPO3\CMS\Core\Resource\FileRepository \$fileRepository
         */
        \$objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        \$fileRepository = \$objectManager->get('TYPO3\CMS\Core\Resource\FileRepository');

        \$files = array();
        foreach (\$fileRepository->findByRelation(\$tcaTableName, \$tcaFieldName, \$uid) as \$falObject) {
            /**
             * @var \$falObject \TYPO3\CMS\Core\Resource\FileReference
             */
            \$files[] = \$falObject->getProperties();
        }

        return \$files;
    }

    /**
     * @var string
     */
    protected \$rootPath = 'EXT:mask/Resources/Private/Backend/';

    /**
     * Preprocesses the preview rendering of a content element.
     *
     * @param PageLayoutView \$parentObject
     * @param bool \$drawItem
     * @param string \$headerContent
     * @param string \$itemContent
     * @param array \$row
     */
    public function preProcess(PageLayoutView &\$parentObject, &\$drawItem, &\$headerContent, &\$itemContent, array &\$row)
    {
        if (!isset(\$this->supportedContentTypes[\$row['CType']])) {
            return;
        }

        \$contentType = explode('_', \$row['CType'], 2);
        \$templateKey = GeneralUtility::underscoredToUpperCamelCase(\$contentType[1]);
        \$templatePath = GeneralUtility::getFileAbsFileName(\$this->rootPath . 'Templates/Content/' . \$templateKey . '.html');
        if (!file_exists(\$templatePath)) {
            return;
        }

        \$objectManager = GeneralUtility::makeInstance(ObjectManager::class);
        \$view = \$objectManager->get(StandaloneView::class);
        \$view->setTemplatePathAndFilename(\$templatePath);
        \$view->setLayoutRootPaths([\$this->rootPath . 'Layouts/']);
        \$view->setPartialRootPaths([\$this->rootPath . 'Partials/']);
        
        \$formDataGroup = GeneralUtility::makeInstance(TcaDatabaseRecord::class);
        \$formDataCompiler = GeneralUtility::makeInstance(FormDataCompiler::class, \$formDataGroup);
        \$formDataCompilerInput = [
            'tableName' => 'tt_content',
            'vanillaUid' => (int)\$row['uid'],
            'command' => 'edit',
        ];
        \$result = \$formDataCompiler->compile(\$formDataCompilerInput);
        \$processedRow = \$this->getProcessedData(\$result['databaseRow'], \$result['processedTca']['columns']);

        \$view->assignMultiple(
            [
                'row' => \$row,
                'processedRow' => \$processedRow,
            ]
        );

        \$itemContent = \$view->render();
        \$drawItem = false;
    }

    /**
     * @param array \$databaseRow
     * @param array \$processedTcaColumns
     * @return array
     */
    protected function getProcessedData(array \$databaseRow, array \$processedTcaColumns)
    {
        \$processedRow = \$databaseRow;
        foreach (\$processedTcaColumns as \$field => \$config) {
            if (!isset(\$config['children'])) {
                continue;
            }
            \$processedRow[\$field] = [];
            \$i = 0;
            foreach (\$config['children'] as \$child) {
                \$processedRow[\$field][] = \$this->getProcessedData(\$child['databaseRow'], \$child['processedTca']['columns']);
                \$files = \$this->getFalFiles(\$processedRow[\$field][\$i]['uid'], 'tt_content', 'tx_' . \$processedRow[\$field][\$i]['CType'][0]);
                if (count(\$files) > 0) {
                    \$processedRow[\$field][\$i]['data_tx_' . \$processedRow[\$field][\$i]['CType'][0] . '_files'] = [];
                    \$processedRow[\$field][\$i]['data_tx_' . \$processedRow[\$field][\$i]['CType'][0] . '_files'] = \$files;
                }
                \$i++;
            }

        }

        return \$processedRow;
    }
}

EOS
        );
    }

    /**
     * Ensure proper labels in $GLOBALS['TCA'] configuration
     */
    protected function replaceTableLabels()
    {
        foreach ($this->maskConfiguration as $table => $_) {
            if (!isset($GLOBALS['TCA'][$table]) || empty($GLOBALS['TCA'][$table]['columns'])) {
                continue;
            }

            $GLOBALS['TCA'][$table]['columns'] = $this->replaceFieldLabels($GLOBALS['TCA'][$table]['columns'], $table);
        }
    }

    /**
     * @param array $elements
     */
    protected function addFluidTemplates(array $elements)
    {
        foreach ($elements as $key => $element) {
            if (empty($element['columns'])) {
                continue;
            }

            $templateKey = GeneralUtility::underscoredToUpperCamelCase($key);
            foreach ($element['columns'] as $field) {
                $field = isset($GLOBALS['TCA'][$this->table]['columns'][$field]) ? $field : 'tx_mask_' . $field;
                if (!isset($GLOBALS['TCA'][$this->table]['columns'][$field])) {
                    continue;
                }
                $this->appendPlainTextFile(
                    $this->templatesFilePath . 'Content/' . $templateKey . '.html',
                    $this->fluidCodeGenerator->generateFluid($this->table, $field)
                );
            }
        }
    }
}
