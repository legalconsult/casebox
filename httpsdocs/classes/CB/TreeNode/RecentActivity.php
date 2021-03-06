<?php
namespace CB\TreeNode;

use CB\L;
use CB\Util;
use CB\Search;

class RecentActivity extends Base
{

    protected function acceptedPath()
    {
        $p = &$this->path;

        // can't be a root folder
        if (sizeof($p) == 0) {
            return false;
        }

        //get the configured 'pid' property for this tree plugin
        //default is 0
        //thats the parent node id where this class shold start to give result nodes
        $ourPid = @$this->config['pid'];

        // ROOT NODE: check if last node is the one we should attach to
        if ($this->lastNode->id == (String)$ourPid) {
            return true;
        }

        // CHILDREN NODES: accept if last node is an instance of this class
        if (get_class($this->lastNode) == get_class($this)) {
            return true;
        }

        return false;
    }

    protected function createDefaultFilter()
    {
        $this->fq = array();

        if (!empty($this->config['includeTemplates'])) {
            $ids = Util\toNumericArray($this->config['includeTemplates']);
            if (!empty($ids)) {
                $this->fq[] = 'template_id:(' . implode(' OR ', $ids) . ')';
            }
        } elseif (!empty($this->config['excludeTemplates'])) {
            $ids = Util\toNumericArray($this->config['excludeTemplates']);
            if (!empty($ids)) {
                $this->fq[] = '!template_id:(' . implode(' OR ', $ids) . ')';
            }
        }
    }


    public function getChildren(&$pathArray, $requestParams)
    {
        $this->path = $pathArray;
        $this->lastNode = @$pathArray[sizeof($pathArray) - 1];
        $this->requestParams = $requestParams;
        // $this->rootId = \CB\Browser::getRootFolderId();

        if (!$this->acceptedPath()) {
            return;
        }

        $this->createDefaultFilter();

        $rez = array();

        $ourPid = @$this->config['pid'];

        if ($this->lastNode->id == (String)$ourPid) {
            $rez = $this->getRootNode();
        } else {
            switch ($this->lastNode->getClassDepth()) {
                case 1:
                    $rez = $this->getGroups();
                    break;

                case 2:
                default:
                    $rez = $this->getGroupItems();

                    break;
            }
        }

        return $rez;
    }

    public function getName($id = false)
    {
        if ($id === false) {
            $id = $this->id;
        }

        $rez = $id;

        switch ($id) {
            case 'recent':
            case 'commented':
            case 'modified':
            case 'added':
                return L\get(ucfirst($id));

            default:
                if (!empty($id) && is_numeric($id)) {
                    $rez = Search::getObjectNames($id);

                    $rez = empty($rez)
                        ? null
                        : array_shift($rez);
                }
                break;
        }

        return $rez;
    }

    protected function getRootNode()
    {
        return array(
            'data' => array(
                array(
                    'name' => $this->getName('recent')
                    ,'id' => $this->getId('recent')
                    ,'iconCls' => 'icon-folder'
                    ,'has_childs' => true
                )
            )
        );
    }

    public function getGroups()
    {
        $isFromGrid = (@$this->requestParams['from'] == 'grid');

        return array(
            'data' => array(
                array(
                    'name' => $this->getName('commented')
                    ,'id' => $this->getId('commented')
                    ,'iconCls' => 'icon-folder'
                    ,'has_childs' => $isFromGrid
                )
                ,array(
                    'name' => $this->getName('modified')
                    ,'id' => $this->getId('modified')
                    ,'iconCls' => 'icon-folder'
                    ,'has_childs' => $isFromGrid
                )
                ,array(
                    'name' => $this->getName('added')
                    ,'id' => $this->getId('added')
                    ,'iconCls' => 'icon-folder'
                    ,'has_childs' => $isFromGrid
                )
            )
        );

    }

    public function getGroupItems()
    {
        $params = $this->requestParams;

        $params['fq'] = $this->fq;

        switch ($this->lastNode->id) {
            case 'commented':
                $params['fq'][] = 'comment_user_id: [* TO *]';
                $params['strictSort'] = 'comment_date desc';
                break;

            case 'modified':
                $params['fq'][] = 'uid: [* TO *]';
                $params['strictSort'] = 'udate desc';
                break;

            case 'added':
                $params['fq'][] = 'cid: [* TO *]';
                $params['strictSort'] = 'cdate desc';
                break;

        }

        $s = new \CB\Search();
        $rez = $s->query($params);

        return $rez;
    }

    /**
     * get param for this node
     *
     * @param  varchar $param for now using to get 'facets' or 'DC'
     * @return array
     */
    public function getNodeParam($param = 'facets')
    {
        $rez = false;
        $sort = null;
        $id = $this->id . '_' . $param;

        if (!empty($this->config[$id])) {
            $rez = $this->config[$id];
        }

        if (!empty($this->config[$id . '_sort'])) {
            $sort = $this->config[$id . '_sort'];
        }

        if ($rez === false) {
            return parent::getNodeParam($param);
        }

        return array(
            'from' => $this->getId()
            ,'data' => $rez
            ,'sort' => $sort
        );

    }
}
