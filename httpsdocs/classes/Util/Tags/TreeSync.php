<?php
namespace Util\Tags;

use CB\DB as DB;
use CB\Browser as Browser;
use CB\L as L;

class TreeSync extends \Util\TreeSync
{
    protected $targetFolderName = 'Thesauri';
    // template config for thesauri nodes
    private $thesauriTemplateConfig = array(
        'name' => 'Thesauri item'
        ,'title' => 'Thesauri item'
        ,'custom_title' => null
        ,'l1' => 'Thesauri item'
        ,'l2' => 'Thesauri item'
        ,'l3' => 'Thesauri item'
        ,'l4' => 'Thesauri item'
        ,'iconCls' => 'icon-tag-small'
        ,'type' => 'object'
        ,'fields' => array(
            array(
                'name' => 'iconCls'
                ,'l1' => 'Icon class'
                ,'l2' => 'Icon class'
                ,'l3' => 'Icon class'
                ,'l4' => 'Icon class'
                ,'type' => 'iconcombo'
                ,'order' => 5
            )
            ,array(
                'name' => 'visible'
                ,'l1' => 'Active'
                ,'l2' => 'Active'
                ,'l3' => 'Active'
                ,'l4' => 'Active'
                ,'type' => 'checkbox'
                ,'order' => 6
            )
            ,array(
                'name' => 'order'
                ,'l1' => 'Order'
                ,'l2' => 'Order'
                ,'l3' => 'Order'
                ,'l4' => 'Order'
                ,'type' => 'int'
                ,'order' => 7
            )
        )
    );

    protected function init()
    {
        parent::init();

        /* adjust thesauri template config */
        $this->thesauriTemplateConfig['pid'] = $this->mainPid;
        $this->thesauriTemplateConfig['template_id'] = $this->mainTemplateId;
        $this->thesauriTemplateConfig['title_template'] = '{'.\CB\LANGUAGE.'}';

        $i = 0;
        foreach ($GLOBALS['languages'] as $language) {
            $field = array(
                'name' => $language
                ,'l1' => 'Title ('.$language.')'
                ,'l2' => 'Title ('.$language.')'
                ,'l3' => 'Title ('.$language.')'
                ,'l4' => 'Title ('.$language.')'
                ,'type' => 'varchar'
                ,'order' => $i
                ,'cfg' => array(
                    'showIn' => 'top'
                )
            );
            $this->thesauriTemplateConfig['fields'][] = $field;
            $i++;
        }

    }

    /**
     * check if template does not already exist and create it if needed
     * @return void
     */
    protected function createOrUpdateThesauriTemplate()
    {
        $this->thesauriTemplateId = null;
        $res = DB\dbQuery(
            'SELECT id
            FROM templates
            WHERE name = $1
                AND type = $2
                AND pid = $3',
            array(
                $this->thesauriTemplateConfig['name']
                ,$this->thesauriTemplateConfig['type']
                ,$this->mainPid
            )
        ) or die(DB\dbQueryError());

        if ($r = $res->fetch_assoc()) {
            $this->thesauriTemplateId = $r['id'];
        }
        $res->close();

        $thesauriTemplateObject = new \CB\Objects\Template();

        if (empty($this->thesauriTemplateId)) {
            $thesauriTemplateObject->setData($this->thesauriTemplateConfig);
            $this->thesauriTemplateId = $thesauriTemplateObject->create();
        } else {
            $this->thesauriTemplateConfig['id'] = $this->thesauriTemplateId;
            $thesauriTemplateObject->setData($this->thesauriTemplateConfig);
            $thesauriTemplateObject->update();
        }
    }

    public function execute()
    {
        $this->init();

        $this->loadTags();

        echo "Start transaction\n";
        DB\startTransaction();

        echo "Create or update thesauri template\n";
        $this->createOrUpdateThesauriTemplate();

        // now that we've checked/created basic thesauri template -
        // add this item to be available in target folder menu
        echo "Update Menu for target folder ".$this->mainPid."\n";
        Browser\CreateMenu::updateMenuForNode(
            $this->mainPid,
            array(
                $this->thesauriTemplateId
                ,$this->DFT
            )
        );

        if (!isset($this->genericObject)) {
            $this->genericObject = new \CB\Objects\Object();
        }

        // prepare languages association from fields
        $languages = \CB\getOption('LANGUAGES');
        $fields = L\languageStringToFieldNames($languages);
        $languages = explode(',', $languages);
        $fields = explode(',', $fields);
        $this->languageFields = array_combine($fields, $languages);

        // update possible cyclic references in templates_structure to template_id
        DB\dbQuery('UPDATE templates_structure SET pid = template_id WHERE pid = id') or die(DB\dbQueryError());

        echo " Start updating tags:\n";
        $this->createOrUpdateTags($this->rootNodes);

        echo "Update tags references ... \n";
        $this->updateTagsReferences();

        echo "Done\n";

        DB\commitTransaction();

        echo "Updating solr ... \n";

        $solrClient = new \CB\Solr\Client();
        $solrClient->updateTree(
            // array('all' => true)
        );
    }

    /**
     * load all tags into an array $this->tags
     * while loading we'll detect root nodes of tags tree
     * and will create associative array of childs
     * @return void
     */
    protected function loadTags()
    {
        $this->tags = array();
        $this->rootNodes = array();

        /* select all tags by excluding user tags (group_id is null)*/
        $res = DB\dbQuery(
            'SELECT *
            FROM tags
            WHERE group_id IS NULL
                AND user_id IS NULL
                AND l'.\CB\LANGUAGE_INDEX.' IS NOT NULL'
        ) or die(DB\dbQueryError());

        while ($r = $res->fetch_assoc()) {
            $this->tags[$r['id']] = $r;
        }
        $res->close();

        // we have loaded all tags and now we are going to
        // update childs and parent references and detect root nodes
        foreach ($this->tags as $tagId => &$tag) {
            if (isset($this->tags[$tag['pid']])) {
                $parent = &$this->tags[$tag['pid']];
                $parent['childs'][$tag['id']] = &$tag;
                $tag['parent'] = &$parent;
            } else {
                $this->rootNodes[$tag['id']] = &$tag;
            }
        }
    }

    /**
     * create/update tags in tree by keeping exact structure from populated tags property
     * @return void
     */
    protected function createOrUpdateTags(&$nodesArray)
    {

        foreach ($nodesArray as $id => &$node) {
            $pid = isset($node['parent'])
                ? $node['parent']['id']
                : $this->mainPid;
            $data = $this->getNodeDataForTree($node);
            $data['id'] = \CB\Objects::getChildId($pid, $node['l'.\CB\LANGUAGE_INDEX]);
            $data['pid'] = $pid;
            if (is_null($data['id'])) {
                $data['id'] = $this->genericObject->create($data);
            } else {
                $this->genericObject->update($data);
            }

            $node['oldId'] = $node['id'];
            $node['id'] = $data['id'];

            if (!empty($node['childs'])) {
                $this->createOrUpdateTags($node['childs']);
            }
        }
    }

    /**
     * get generic node data for creating in tree from tag node properties
     * @param  array $node
     * @return array
     */
    protected function getNodeDataForTree(&$node)
    {
        $rez = array(
            'name' => $node['l'.\CB\LANGUAGE_INDEX]
            ,'data' => array(
            )
        );
        if ($node['type'] == 1) { //tag
            $rez['template_id'] = $this->thesauriTemplateId;
            foreach ($this->languageFields as $field => $language) {
                if (!empty($node[$field])) {
                    $rez['data'][$language] = $node[$field];
                }
            }
            if (!empty($node['iconCls'])) {
                $rez['data']['iconCls'] = $node['iconCls'];
            }
            if (empty($node['hidden'])) {
                $rez['data']['visible'] = 1;
            }
            if (!empty($node['order'])) {
                $rez['data']['order'] = $node['order'];
            }
        } else { //folder
            $rez['template_id'] = $this->DFT;
            $rez['data']['_title'] = $rez['name'];
        }

        return $rez;
    }

    /**
     * update references to a tag from template configs and from all objects values
     * @return void
     */
    protected function updateTagsReferences()
    {
        //we-ll collect all distinct modified tags with all their parents
        //because template configs could contain parent id in config
        //but in objects are stored their child values.
        //An also could be dependent child values.

        $modifiedTags = array();
        foreach ($this->tags as $oldId => &$tag) {
            if ($tag['oldId'] != $tag['id']) {
                $modifiedTags[$tag['oldId']] = $tag['id'];
                // collect all parents
                $parent = @$tag['parent'];
                while (!empty($parent)) {
                    $modifiedTags[$parent['oldId']] = $parent['id'];
                    $parent = @$parent['parent'];
                }
            }
        }
        $this->modifiedTags = &$modifiedTags;

        //select templates that have references to modified tag and update their properties
        $tc = \CB\Templates\SingletonCollection::getInstance();
        $tc->loadAll();
        $modifiedTemplateIds = array();
        foreach ($tc->templates as $template) {
            $data = $template->getData();
            $modified = false;
            foreach ($data['fields'] as &$field) {
                if (!empty($field['cfg']['thesauriId'])) {
                    // if (isset($modifiedTags[$field['cfg']['thesauriId']])) {
                        // $field['cfg']['thesauriId'] = $modifiedTags[$field['cfg']['thesauriId']];
                        // $modified = true;
                    // }
                    $field['cfg']['source'] = 'tree';
                    if ($field['cfg']['thesauriId'] == 'dependent') {
                        $field['cfg']['dependency'] = array();
                        $field['cfg']['scope'] = 'variable';
                    } else {
                        if (isset($this->tags[$field['cfg']['thesauriId']]['id'])) {
                            $field['cfg']['scope'] = $this->tags[$field['cfg']['thesauriId']]['id'];
                        }
                    }
                    unset($field['cfg']['thesauriId']);
                    $field['cfg']['renderer'] = 'listObjIcons';

                    if (isset($field['editor']) && ($field['editor'] == 'popuplist')) {
                        $field['cfg']['editor'] = 'form';
                    }
                    $field['type'] = '_objects';
                    $modified = true;
                }
            }
            if ($modified) {
                $template->update($data);
                $modifiedTemplateIds[$data['id']] = 1;
            }
        }

        //now selecting all object of modified template types and update their values

        if (!empty($modifiedTemplateIds)) {
            $res = DB\dbQuery(
                'SELECT id FROM tree WHERE template_id in ('.implode(',', $modifiedTemplateIds).')'
            ) or die(DB\dbQueryError());
            while ($r = $res->fetch_assoc()) {
                $obj = \CB\Objects::getCustomClassByObjectId($r['id']);
                $obj->load();
                $template = $obj->getTemplate();
                $data = $obj->getData();

                $objUpdated = false;
                $processFields = array();
                foreach ($data as $fn => &$fv) {
                    $tf = $template->getField($fn);
                    $processFields[] = array($tf, &$fv);
                }

                while (!empty($processFields)) {
                    $field = array_shift($processFields);
                    $tf = &$field[0];
                    $fv = &$field[1];
                    if (!empty($tf['cfg']['thesauriId'])) {
                        if (is_array($fv) && !array_key_exists('value', $fv)) {
                            foreach ($fv as &$mfv) {
                                $this->modifyFieldValue($mfv);
                                if (!empty($mfv['childs'])) {
                                    foreach ($mfv['childs'] as $cfn => $cfv) {
                                        $processFields[] = array($template->getField($cfn), &$cfv);
                                    }
                                }
                            }
                        } else {
                            $this->modifyFieldValue($fv);
                            if (!empty($mfv['childs'])) {
                                foreach ($fv['childs'] as $cfn => $cfv) {
                                    $processFields[] = array($template->getField($cfn), &$cfv);
                                }
                            }
                        }
                    }
                }

                $obj->update($data);
            }
            $res->close();
        }
    }
    /**
     * modify field value to new changed tag ids
     * @param  variant $fieldValue
     * @return boolean if field changed
     */
    protected function modifyFieldValue(&$fieldValue)
    {
        $rez = false;
        if (is_array($fieldValue)) {
            if (!empty($fieldValue['value'])) {
                $vals = explode(',', $fieldValue['value']);
                for ($i=0; $i < sizeof($vals); $i++) {
                    if (!empty($this->modifiedTags[$vals[$i]])) {
                        $vals[$i] = $this->modifiedTags[$vals[$i]];
                    }
                }
                $fieldValue['value'] = implode(',', $vals);
            } else {
                return $rez;
            }
        } else {
            if (empty($fieldValue)) {
                return $rez;
            } else {
                $vals = explode(',', $fieldValue);
                for ($i=0; $i < sizeof($vals); $i++) {
                    if (!empty($this->modifiedTags[$vals[$i]])) {
                        $vals[$i] = $this->modifiedTags[$vals[$i]];
                    }
                }
                $fieldValue = implode(',', $vals);
            }
        }
    }
}