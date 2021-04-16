<?php

declare(strict_types=1);

namespace IchHabRecht\MaskExport\Aggregate;

/*
 * This file is part of the TYPO3 extension mask_export.
 *
 * (c) 2016 Nicole Cordes <typo3@cordes.co>, CPS-IT GmbH
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

use TYPO3\CMS\Core\Utility\ArrayUtility;

class TcaAggregate extends AbstractAggregate implements LanguageAwareInterface, PhpAwareInterface, SqlAwareInterface
{
    use PhpAwareTrait;
    use SqlAwareTrait;
    use TcaAwareTrait;

    /**
     * @var string
     */
    protected $tcaFilePath = 'Configuration/TCA/';

    protected function process()
    {
        if (empty($this->maskConfiguration)) {
            return;
        }

        $newTcaTables = array_diff_key($this->maskConfiguration, $this->systemTables);
        ksort($newTcaTables);

        if (empty($newTcaTables)) {
            return;
        }

        foreach ($newTcaTables as $table => $configuration) {
            if (!isset($GLOBALS['TCA'][$table])) {
                continue;
            }

            $this->table = $table;
            $tableConfiguration = $GLOBALS['TCA'][$table];

            $tcaConfiguration = $this->replaceTableLabels($tableConfiguration);
            $tcaConfiguration['ctrl']['iconfile'] = 'EXT:mask/Resources/Public/Icons/Extension.svg';
            $this->addTableSqlDefinitions($tableConfiguration);
            $this->addPhpFile(
                $this->tcaFilePath . $table . '.php',
                'return ' . ArrayUtility::arrayExport($tcaConfiguration) . ';'
            );
        }

        $tableList = implode(', ', array_keys($newTcaTables));
        $this->appendPhpFile(
            'ext_tables.php',
            <<<EOS
\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::allowTableOnStandardPages('{$tableList}');

EOS
            ,
            PhpAwareInterface::PHPFILE_DEFINED_TYPO3_MODE | PhpAwareInterface::PHPFILE_CLOSURE_FUNCTION
        );
    }

    /**
     * @param array $tableConfiguration
     * @return array
     */
    protected function replaceTableLabels(array $tableConfiguration)
    {
        $title = $this->table;
        if (empty($tableConfiguration['ctrl']['title']) || 0 !== strpos($tableConfiguration['ctrl']['title'], 'LLL:')) {
            $title = $tableConfiguration['ctrl']['title'];
        }
        $this->addLabel(
            $this->languageFilePath . $this->languageFileIdentifier,
            $this->table,
            $title
        );
        $tableConfiguration['ctrl']['title'] = 'LLL:EXT:mask/'
            . $this->languageFilePath . $this->languageFileIdentifier . ':' . $this->table;

        if (!empty($tableConfiguration['columns'])) {
            $tableConfiguration['columns'] = $this->replaceFieldLabels($tableConfiguration['columns'], $this->table);
            $tableConfiguration['columns'] = $this->replaceItemsLabels($tableConfiguration['columns'], $this->table);
        }

        return $tableConfiguration;
    }

    /**
     * @param array $tableConfiguration
     */
    protected function addTableSqlDefinitions(array $tableConfiguration)
    {
        $sqlDefinitions = [
            'uid' => 'int(11) NOT NULL auto_increment',
            'pid' => 'int(11) DEFAULT \'0\' NOT NULL',
            'parentid' => 'int(11) DEFAULT \'0\' NOT NULL',
            'parenttable' => 'varchar(255) DEFAULT \'\' NOT NULL',
            'sorting' => 'int(11) unsigned DEFAULT \'0\' NOT NULL',
            't3ver_oid' => 'int(11) unsigned DEFAULT \'0\' NOT NULL',
            't3ver_id' => 'int(11) DEFAULT \'0\' NOT NULL',
            't3ver_wsid' => 'int(11) DEFAULT \'0\' NOT NULL',
            't3ver_label' => 'varchar(255) DEFAULT \'\' NOT NULL',
            't3ver_state' => 'tinyint(4) DEFAULT \'0\' NOT NULL',
            't3ver_stage' => 'int(11) DEFAULT \'0\' NOT NULL',
            't3ver_count' => 'int(11) DEFAULT \'0\' NOT NULL',
            't3ver_tstamp' => 'int(11) DEFAULT \'0\' NOT NULL',
            't3ver_move_id' => 'int(11) DEFAULT \'0\' NOT NULL',
        ];

        if (!empty($tableConfiguration['ctrl']['tstamp'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['tstamp']] = 'int(11) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['crdate'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['crdate']] = 'int(11) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['cruser_id'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['cruser_id']] = 'int(11) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['delete'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['delete']] = 'tinyint(4) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['enablecolumns']['disabled'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['enablecolumns']['disabled']] = 'tinyint(4) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['enablecolumns']['starttime'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['enablecolumns']['starttime']] = 'int(11) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['enablecolumns']['endtime'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['enablecolumns']['endtime']] = 'int(11) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['languageField'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['languageField']] = 'int(11) DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['transOrigPointerField'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['transOrigPointerField']] = 'int(11) unsigned DEFAULT \'0\' NOT NULL';
        }
        if (!empty($tableConfiguration['ctrl']['transOrigDiffSourceField'])) {
            $sqlDefinitions[$tableConfiguration['ctrl']['transOrigDiffSourceField']] = 'mediumblob';
        }

        $this->addSqlDefinitions($this->table, $sqlDefinitions);

        if (!empty($tableConfiguration['columns'])) {
            $this->addFieldsSqlDefinitions($tableConfiguration['columns']);
        }

        $this->addSqlDefinitions($this->table, [
            'PRIMARY KEY' => '(uid)',
            'KEY parent' => '(pid)',
            'KEY t3ver_oid' => '(t3ver_oid,t3ver_wsid)',
            'KEY language' => '(l10n_parent,sys_language_uid)',
        ]);
    }
}
