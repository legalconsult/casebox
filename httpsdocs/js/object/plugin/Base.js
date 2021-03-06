Ext.namespace('CB.object.plugin');

Ext.define('CB.object.plugin.Base', {
    extend: 'Ext.Panel'
    ,border: false
    ,header: false
    ,cls: 'obj-plugin'

    ,initComponent: function(){
        this.prepareToolbar();

        this.enableBubble(['openproperties', 'createobject', 'objectopen']);

        // CB.object.plugin.Base.superclass.initComponent.apply(this, arguments);
        this.callParent(arguments);

    }

    ,onLoadData: function(r, e) {
        if(Ext.isEmpty(r.data)) {
            return;
        }
        //overwrite this method and add your logic
    }

    ,getLoadedObjectProperties: function() {
        var pluginsPanel = this.up('panel');

        return pluginsPanel
            ? pluginsPanel.loadedData
            : {};
    }

    ,prepareToolbar: function()
    {
        if(Ext.isEmpty(this.title) && Ext.isEmpty(this.actions)) {
            return;
        }

        var tbarItems = [];
        if(!Ext.isEmpty(this.title)) {
            tbarItems.push({
                xtype: 'label'
                ,cls: 'title'
                ,text: this.title
            });
        }

        var items = this.getToolbarItems();

        if(!Ext.isEmpty(items)) {
            tbarItems.push('->');
            for (var i = 0; i < items.length; i++) {
                tbarItems.push(items[i]);
            }
        }

        this.tbar = tbarItems;
    }

    ,getToolbarItems: function() {
        return [];
    }

    ,getContainerToolbarItems: function() {
        return {};
    }

    ,openObjectProperties: function(data) {
        clog('openObjectProperties', data);
        this.fireEvent('openproperties', data);
    }

});
