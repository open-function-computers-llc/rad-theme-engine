<?php

namespace ofc;

//build our file, wysiwig, metaboxtoggler
//rename media to image


class FieldHTML
{
    public static function template($type, $meta, $postID, $field)
    {
        $type = self::translateToSafeMethod($type);
        return self::$type($meta, $postID, $field);
    }

    private static function translateToSafeMethod($type)
    {
        $output = str_replace(' ', '', ucwords(str_replace('-', ' ', $type)));
        $output[0] = strtolower($output[0]);
        return $output;
    }

    public static function wysiwyg($meta, $postID, $field)
    {

        //create wysiwyg editor
        $settings = array(
            'textarea_name' => 'post_text',
            'default_editor' => 'TinyMce',
        );
        $wpEditor = wp_editor($meta, "rad_".$field['name']."_wysiwyg", $settings);

        return <<<HTML
            

            <div class="wysisyg">
            
                <script>
                    label = '{{field.label}}'
                    wysName = '{{field.name}}'
                    value = "{{value}}"
                    meta = '{{ meta }}'


                    window.onload = function(){
                        //find elements of the editor
                        const wysiwygField = document.getElementById('rad_'+wysName+'_wysiwyg_ifr').contentWindow.document.getElementById('tinymce')
                        console.log(label)
                        const textareaField = document.getElementById('rad_'+wysName+'_wysiwyg')
                        const inputField = document.getElementById('rad_'+wysName)

                        // We have to use js to add the label for WYSIWYG field, otherwise it is under the field
                        var paragraph = document.createElement("p");
                        paragraph.className = "post-attributes-label-wrapper";

                        // Create a new label element
                        var labelElement = document.createElement("label");
                        labelElement.className = "post-attributes-label";
                        labelElement.htmlFor = "rad_"+name;
                        labelElement.textContent = label;

                        // Append the label to the paragraph
                        paragraph.appendChild(labelElement);
                        var targetElement = document.getElementById("wp-rad_"+wysName+"_wysiwyg-wrap");
                        targetElement.prepend(paragraph);
                        

                        //on each keystroke update the input field
                        wysiwygField.addEventListener("input", function(){
                            content = tinymce.activeEditor.getContent({format: "html"})
                            inputField.value=content
                        })
                        textareaField.addEventListener("input", function(){
                            inputField.value=textareaField.value
                        })
                    }

                </script>
                <!-- input field that stores data -->
                <input type="hidden" name="rad_{{ field.name }}" id="rad_{{ field.name }}" value="{{value}}"/>

            </div>

        HTML;


    }

    public static function text($meta, $postID, $field)
    {
        return <<<HTML
            <p class="post-attributes-label-wrapper">
                <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
            </p>
            <input type="text" id="rad_{{ field.name }}" name="rad_{{ field.name }}" value="{{ value }}" />
        HTML;
            // {{ template }}
    }

    public static function number($meta, $postID, $field)
    {
        return <<<HTML
            <p class="post-attributes-label-wrapper">
                <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
            </p>
            <input type="number" id="rad_{{ field.name }}" name="rad_{{ field.name }}" value="{{ value }}" />
        HTML;
    }

    public static function textarea($meta, $postID, $field)
    {
        return <<<HTML
            <p class="post-attributes-label-wrapper">
                <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
            </p>
            <textarea style="width: 100%; height: 220px;" id="rad_{{ field.name }}" name="rad_{{ field.name }}">{{ value }}</textarea>
        HTML;
    }

    public static function repeater($meta, $postID, $field)
    {
        return <<<HTML
        <p class="post-attributes-label-wrapper">
            <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
        </p>

        <div id="repeater-{{ field.name }}">
            {{#raw}}
            <input type="hidden" :name="id" :id="id" :value="valueString" />

            <div v-if="!value">
                <p>No elements</p>
            </div>
            <div v-else v-for="(row, i) in value" :key="'row-'+i">
                <div>{{ i+1 }}</div>
                <div v-for="(e, j) in row" :key="'e-'+j+'-row-'+i">
                    <label>{{ e.label }}</label>

                    <input v-if="e.type === 'text'" type="text" v-model="e.value" @change="updateValueString" />
                    <a href="#" v-if="e.type === 'media'" @click.prevent="setMediaFor(e)" class="button">Choose Media</a>
                </div>
            </div>


            <div>
                <a href="#" class="button" @click.prevent="addRow">Add Row</a>
            </div>
            {{/raw}}
        </div>
            <script>
            if (!window.vm) {
                var vm = [];
            }
            vm["{{ field.name }}"] = new Vue({
                el: "#repeater-{{ field.name }}",
                data: {
                    value: {{#json-encode value }},
                    valueString: "",
                    id: 'rad_{{ field.name }}',
                    newShape: {{#json-encode field.sub}},
                    fileFrame: null,
                    fileFrameTarget: null,
                },
                methods: {
                    addRow() {
                        var thingToPush = [];
                        for (let index = 0; index < this.newShape.length; index++) {
                            var e = this.newShape[index];
                            thingToPush.push(Object.assign({value: ""}, e));
                        }
                        this.value.push(thingToPush);
                    },
                    setMediaFor(ele) {
                        this.fileFrameTarget = ele;
                        this.fileFrame.open();
                    },
                    updateValueString: function() {
                        this.valueString = JSON.stringify(this.value);
                    },
                },
                mounted: function() {
                    if (!Array.isArray(this.value)) {
                        if (this.value.length < 1) {
                            this.value = [];
                        } else {
                            this.value = JSON.parse(this.value);
                        }
                    }

                    this.updateValueString();

                    var self = this;
                    jQuery(document).ready(function() {
                        self.fileFrame = wp.media({
                            frame: 'select',
                            state: 'mystate',
                            library: {type: 'image'},
                            multiple: false
                        });
                        self.fileFrame.states.add([
                            new wp.media.controller.Library({
                                id: 'mystate',
                                title: 'Choose Media',
                                priority: 20,
                                toolbar: 'select',
                                filterable: 'uploaded',
                                library: wp.media.query( self.fileFrame.options.library ),
                                multiple: false,
                                editable: true,
                                displayUserSettings: false,
                                displaySettings: false,
                                allowLocalEdits: true
                            })
                        ]);
                        self.fileFrame.on('select', function() {
                            if (self.store === 'url') {
                                self.fileFrameTarget.value = self.fileFrame.state().get('selection').first().toJSON().url;
                                return;
                            }
                            // default storage is json
                            self.fileFrameTarget.value = self.fileFrame.state().get('selection').first().toJSON();
                            self.fileFrameTarget = null;
                            self.updateValueString();
                        });
                    });
                }
            });
            </script>
        HTML;
    }

    public static function image($meta, $postID, $field)
    {
        return <<<HTML
            <p class="post-attributes-label-wrapper">
                <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
                <div id="media-chooser-{{ field.name }}">
                    <script type="text/javascript">

                        //get all the variables associated with each image
                        meta = '{{ value }}'
                        postID = '{{ id }}';
                        name = "{{ field.name }}";
                        store = "{{ field.store }}";

                        //get container for image
                        container = document.getElementById('media-chooser-'+name);

                        //create image element to display currently set image if store type is url
                        if (store === 'url') {
                            function createIMG(metaPassed, namePassed, containerPassed){
                                image = document.createElement('img');
                                image.style.maxWidth = '300px';
                                image.src = metaPassed;
                                image.id=namePassed;
                                containerPassed.prepend(image);
                            }
                            if(meta){
                                createIMG(meta, name, container)
                            }

                        }

                        //create table element to display image and other associated information if store type is json
                        function createTableElements(div, table, textContent1, textContent2, idInput){

                            tr1 = document.createElement('tr');
                            td1 = document.createElement('td');
                            td1.style.fontWeight = 'bold';
                            td1.textContent = textContent1

                            td2 = document.createElement('td');
                            td2.textContent = textContent2;
                            td2.id=idInput

                            tr1.appendChild(td1)
                            tr1.appendChild(td2)

                            table.appendChild(tr1);
                        }

                        //not done, still need more values
                        if(store === 'json'){
                            //get the meta to readable json
                            //before this it is an ugly string, this makes js able to parse it
                            //create the image div to preview
                            function createTableIMG(url, name, postID, width, height, filesizeHumanReadable){
                                image = document.createElement('img');
                                image.style.maxWidth = '300px';
                                image.src = url;
                                image.id=name;
                                div = document.createElement('div')
                                table = document.createElement('table');
                                //create each table element
                                //each element displays either the post ID of the image, its URL, its dimmentisons, and its size
                                createTableElements(div, table, "ID: ", postID, name+'_id')
                                createTableElements(div, table, "URL: ", url, name+'_url')
                                //Probably need to change the full size at some point.
                                createTableElements(div, table, "Dimmensions: ",width+'x'+height, name+'_dim')
                                createTableElements(div, table, "Size: ", filesizeHumanReadable, name+'_size')

                                div.appendChild(table)
                                container.prepend(div)
                                container.prepend(image);
                            }
                            if(meta){
                                meta = meta.replace(/&quot;/g, '\\"');
                                meta = JSON.parse(meta)
                                createTableIMG(meta.url, name, postID, meta.sizes.full.width, meta.sizes.full.height, meta.filesizeHumanReadable)
                            }
                        }
                    </script>

                    <!-- Input field for each image -->
                    <input type="hidden" name="rad_{{ field.name }}" id="rad_{{ field.name }}" value="{{value}}"/>

                    <br />
                    <!-- The button to press to open modal -->
                    <!-- MUST PASS IN THE FIELD NAME AND STORE -->
                    <a href="#" onclick="chooseMedia('{{field.name}}', '{{field.store}}')" class="button">Choose Image</a>

                </div>
            </p>

            <script>
                // set up modal
                function chooseMedia(fieldName, store) {
                    self.fileFrame = wp.media({
                        frame: 'select',
                        state: 'mystate',
                        library: { type: 'image' },
                        multiple: false
                    });

                    fileFrame.states.add([
                        new wp.media.controller.Library({
                            id: 'mystate',
                            title: fieldName,
                            priority: 20,
                            toolbar: 'select',
                            filterable: 'uploaded',
                            library: wp.media.query(fileFrame.options.library),
                            multiple: false,
                            editable: true,
                            displayUserSettings: false,
                            displaySettings: false,
                            allowLocalEdits: true
                        })

                    ]);

                    //If image is selected update the input field value

                    fileFrame.on('select', function() {
                        inputField = document.getElementById('rad_'+fieldName)

                        attachment = fileFrame.state().get('selection').first().toJSON();

                        //check how we are to store the img
                        if(store == 'url')
                        {
                            //get image element
                            imgSrc = document.getElementById(fieldName)

                            //if it doesn't exist on the frontend make it and display so the user can see the image
                            if(imgSrc == null){
                                container = document.getElementById('media-chooser-'+fieldName)
                                createIMG(attachment.url, attachment.name, container)
                            }

                            //otherwise just updated the image
                            else{
                                imgSrc.src = attachment.url
                            }
                            //save it to the backend
                            inputField.value = attachment.url

                        } else //if store is json
                        {
                            //update the display immediatly
                            //Does NOT write anything to backend, only shows the user what it will look like.
                            imgSrc = document.getElementById(fieldName)
                            id = document.getElementById(fieldName+'_id')

                            if(id==null){
                                createTableIMG(attachment.url, attachment.name, attachment.id, attachment.sizes.full.width, attachment.sizes.full.height, attachment.filesizeHumanReadable)
                            }
                            else{
                                id = document.getElementById(fieldName+'_id')
                                url = document.getElementById(fieldName+'_url')
                                size = document.getElementById(fieldName+'_size')
                                dim = document.getElementById(fieldName+'_dim')
                                dim.textContent=attachment.sizes.full.width+'x'+attachment.sizes.full.height
                                size.textContent=attachment.filesizeHumanReadable
                                url.textContent=attachment.url
                                id.textContent=attachment.id
                                imgSrc.src = attachment.url

                            }
                            //this writes everything to backend
                            inputField.value = JSON.stringify(attachment)
                        }
                    });

                    //open the modal when button is pressed
                    fileFrame.open();
                };


            </script>

        HTML;
    }


    public static function file($meta, $postID, $field)
    {
        return <<<HTML
            <p class="post-attributes-label-wrapper">
                <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
                <div id="media-chooser-{{ field.name }}">
                    <script type="text/javascript">

                        //get all the variables associated with each file
                        meta = '{{ value }}'
                        postID = '{{ id }}';
                        name = "{{ field.name }}";

                        //get container for file information
                        container = document.getElementById('media-chooser-'+name);

                        //function to create table elements for file info

                        function createTableElements(div, table, textContent1, textContent2, idInput){

                           tr1 = document.createElement('tr');
                           td1 = document.createElement('td');
                           td1.style.fontWeight = 'bold';
                           td1.textContent = textContent1

                           td2 = document.createElement('td');
                           td2.textContent = textContent2;
                           td2.id=idInput

                           tr1.appendChild(td1)
                           tr1.appendChild(td2)

                           table.appendChild(tr1);
                       }


                        //instantiate the table itself

                        function createTable(postID, url, title, filename, filesizeHumanReadable){

                            div = document.createElement('div')
                            table = document.createElement('table');
                            //create each table element
                            //each element displays either the post ID of the image, its URL, its dimmentisons, and its size
                            createTableElements(div, table, "URL: ", url, name+'_url')
                            //Probably need to change the full size at some point.
                            createTableElements(div, table, "Name: ", title, name+'_name')
                            createTableElements(div, table, "File Name: ", filename, name+'_fname')
                            createTableElements(div, table, "Size: ", filesizeHumanReadable, name+'_size')

                            div.appendChild(table)
                            container.appendChild(div)

                        }

                        //check if meta is already populated

                        if(meta){
                            //get the meta to readable json
                            //before this it is an ugly string, this makes js able to parse it
                            meta = meta.replace(/&quot;/g, '\\"');
                            meta = JSON.parse(meta)
                            createTable(postID, meta.url, meta.title, meta.filename, meta.filesizeHumanReadable)
                        }

                    </script>

                    <!-- Input field for each image -->
                    <input type="hidden" name="rad_{{ field.name }}" id="rad_{{ field.name }}" value="{{value}}"/>

                    <br />
                    <!-- The button to press to open modal -->
                    <!-- MUST PASS IN THE FIELD NAME AND STORE -->
                    <a href="#" onclick="chooseFile('{{field.name}}')" class="button">Choose File</a>

                </div>
            </p>

            <script>
                // set up modal
                function chooseFile(fieldName) {
                    self.fileFrame = wp.media({
                        frame: 'select',
                        state: 'mystate',
                        library: { type: 'application' },
                        multiple: false
                    });

                    fileFrame.states.add([
                        new wp.media.controller.Library({
                            id: 'mystate',
                            title: fieldName,
                            priority: 20,
                            toolbar: 'select',
                            filterable: 'uploaded',
                            library: wp.media.query(fileFrame.options.library),
                            multiple: false,
                            editable: true,
                            displayUserSettings: false,
                            displaySettings: false,
                            allowLocalEdits: true
                        })

                    ]);

                    //If file is selected update the input field value

                    fileFrame.on('select', function() {
                        inputField = document.getElementById('rad_'+fieldName)
                        attachment = fileFrame.state().get('selection').first().toJSON();
                        fileName = document.getElementById(fieldName+'_fname')

                        //if there isn't already the filename element
                        //(i.e. this is the first time uploading a file and we need to create the table to display the data)
                        //create the table and populate it with the recently uploaded information

                        if(fileName == null){
                            console.log("null")
                            createTable(attachment.postID, attachment.url, attachment.title, attachment.filename, attachment.filesizeHumanReadable)
                        }
                        //otherwise we can update the existing info
                        else{
                            fileName = document.getElementById(fieldName+'_fname')
                            url = document.getElementById(fieldName+'_url')
                            newName = document.getElementById(fieldName+'_name')
                            size = document.getElementById(fieldName+'_size')

                            size.textContent=attachment.filesizeHumanReadable
                            newName.textContent=attachment.title
                            url.textContent=attachment.url
                            fileName.textContent = attachment.filename
                        }

                        //this writes everything to backend
                        inputField.value = JSON.stringify(attachment)


                    });

                    //open the modal when button is pressed
                    fileFrame.open();
                };


            </script>
        HTML;
    }


    public static function flexRepeater()
    {
        return <<<HTML
        <p class="post-attributes-label-wrapper">
        <label class="post-attributes-label" for="rad_{{ field.name }}">{{ field.label }}</label>
        <div id="media-chooser-{{ field.name }}">
            {{#raw}}
                <input type="hidden" :name="id" :id="id" :value="valueString" />

                <div v-if="!value">
                    <p>You don't have any content variations. Start adding some with the "Add Variation" button below.</p>
                </div>

                <div v-else>
                    <div v-for="(v, i) in value" :key="'variation-'+i">
                        <div class="variation-wrapper">
                            <div class="title">
                                <h3>{{ v.name }}</h3>
                            </div>
                            <div class="controls">
                                <a v-if="i > 0" class="button button-small" style="font-family: dashicons" href="#" @click.prevent="moveUp(i)"></a>
                                <a v-if="i < (value.length - 1)" class="button button-small" style="font-family: dashicons" href="#" @click.prevent="moveDown(i)"></a>
                                <a class="button button-small" style="font-family: dashicons" href="#" @click.prevent="removeVariation(i)"></a>
                            </div>
                            <div class="content">
                                <div v-for="(f, j) in v.fields" :key="'variation-'+i+'-field-'+j" />
                                    <label>{{ f.label }}</label>

                                    <!-- simple fields -->
                                    <input type="text" v-if="f.type === 'text'" v-model="f.value" @change="updateValueString" />
                                    <textarea v-if="f.type === 'textarea'" v-model="f.value" style="width: 100%; height: 220px;" @change="updateValueString"></textarea>

                                    <!-- ajax fields -->
                                    <div v-if="f.type === 'media'" style="position: relative">
                                        <table v-if="chosenMediaParse(f.value)">
                                            <tr>
                                                <td style="font-weight: bold">ID:</td><td>{{ chosenMediaParse(f.value).id }}</td>
                                            </tr>
                                                <td style="font-weight: bold">Size:</td><td>{{ chosenMediaParse(f.value).filesizeHumanReadable }}</td>
                                            </tr>
                                                <td style="font-weight: bold">Dimensions:</td><td>{{ chosenMediaParse(f.value).width }}x{{ chosenMediaParse(f.value).height }}</td>
                                            </tr>
                                                <td style="font-weight: bold">URL:</td><td>{{ chosenMediaParse(f.value).url }}</td>
                                            </tr>
                                        </table>
                                        <div v-if="!f.value" style="border: 1px solid #666; padding: 1rem; margin-bottom: 1rem;">No media chosen</div>
                                        <a href="#" @click.prevent="chooseMedia(f)" class="button">Choose Media</a>
                                    </div>

                                    <!-- ajax fields -->
                                    <div v-if="f.type === 'related'" style="position: relative">
                                        <input type="text" @keyup="relatedTypeAhead(\$event)" v-model="ajaxSearches[f.name]" placeholder="Type to choose..." />
                                        <ul class="pop-open-box">
                                            <li v-for="(r, k) in ajaxResults" :key="'ajax-result-'+k" @click="setValueFor(r, f)">{{ r.title }}</li>
                                        </ul>
                                        <p v-if="f.value">{{ f.value.url }}</p>
                                    </div>
                                </div>
                                <hr />
                            </div>
                        </div>
                    </div>
                </div>

                <div v-if="addingVariation">
                    <p>Type:</p>
                    <select v-model="newVariation">
                        <option v-for="(v, i) in variations" :value="v" :key="'variation-'+i">{{ v.name }}</option>
                    </select>
                    <a href="#" @click.prevent="addVariation" class="button button-small">Add</a>
                    <a href="#" @click.prevent="addingVariation = false; newVariation = null;" class="button button-small">Cancel</a>
                </div>
                <a
                    v-else
                    class="button button-small"
                    href="#"
                    @click.prevent="addingVariation = true"
                    >Add Variation</a>

            {{/raw}}
        </div>
        <style>
            .pop-open-box {
                position: absolute;
                top: 100%;
                left: 0;
                background-color: white;
                padding: 3px;
                width: 100%;
                margin: 0;
            }
            .pop-open-box li {
                padding: 3px;
            }
            .pop-open-box li:hover {
                cursor: pointer;
                background-color: grey;
            }
            .variation-wrapper {
                display: flex;
                flex-wrap: wrap;
            }
            .variation-wrapper h3 {
                margin: 0;
            }
            .variation-wrapper .title {
                flex: 1;
            }
            .variation-wrapper .content {
                width: 100%;
            }
            .variation-wrapper label {
                display: block;
                font-weight: bold;
                margin-top: 10px;
            }
        </style>
        <script>
            if (!window.vm) {
                var vm = [];
            }
            vm["{{ field.name }}"] = new Vue({
                el: "#media-chooser-{{ field.name }}",
                data: {
                    id: 'rad_{{ field.name }}',
                    value: {{#json-encode value}},
                    variations: {{#json-encode field.variations}},
                    addingVariation: false,
                    newVariation: null,
                    valueString: "",
                    ajaxResults: [],
                    ajaxSearches: {},
                    fileFrame: null,
                    fileFrameTarget: null,
                },
                methods: {
                    addVariation: function(v) {
                        this.value.push(Object.assign({value: ""}, this.newVariation));
                        this.newVariation = null;
                        this.addingVariation = false;
                    },
                    moveUp: function(index) {
                        this.value.splice(index-1, 0, this.value.splice(index, 1)[0]);
                    },
                    moveDown: function(index) {
                        this.value.splice(index+1, 0, this.value.splice(index, 1)[0]);
                    },
                    removeVariation: function(index) {
                        this.value.splice(index, 1);
                    },
                    relatedTypeAhead: function(\$event) {
                        var self = this;
                        jQuery.post(ajaxurl, {action: "betterwordpress_related", q: \$event.target.value}, function(res) {
                            self.ajaxResults = JSON.parse(res);
                        });
                    },
                    setValueFor: function(r, f) {
                        f.value = r;
                        this.updateValueString();
                        this.ajaxResults = [];
                        this.ajaxSearches = {};
                    },
                    updateValueString: function() {
                        this.valueString = JSON.stringify(this.value);
                    },
                    chooseMedia: function(f) {
                        this.fileFrameTarget = f;
                        this.fileFrame.open();
                    },
                    chosenMediaParse(data) {
                        if (!data) {
                            return;
                        }
                        // console.log(data);
                        return JSON.parse(data);
                    }
                },
                watch: {
                    value: function() {
                        this.valueString = JSON.stringify(this.value);
                    }
                },
                mounted: function() {
                    if (!Array.isArray(this.value)) {
                        if (this.value.length < 1) {
                            this.value = [];
                        } else {
                            this.value = JSON.parse(this.value);
                        }
                    }

                    var self = this;
                    jQuery(document).ready(function() {
                        self.fileFrame = wp.media({
                            frame: 'select',
                            state: 'mystate',
                            library: {type: 'image'},
                            multiple: false
                        });
                        self.fileFrame.states.add([
                            new wp.media.controller.Library({
                                id: 'mystate',
                                title: 'Choose your media',
                                priority: 20,
                                toolbar: 'select',
                                filterable: 'uploaded',
                                library: wp.media.query( self.fileFrame.options.library ),
                                multiple: false,
                                editable: true,
                                displayUserSettings: false,
                                displaySettings: false,
                                allowLocalEdits: true
                            })
                        ]);
                        self.fileFrame.on('select', function() {
                            if (self.store === 'url') {
                                self.fileFrameTarget.value = self.fileFrame.state().get('selection').first().toJSON().url;
                                self.updateValueString();
                                return;
                            }

                            // default storage is json
                            self.fileFrameTarget.value = JSON.stringify(self.fileFrame.state().get('selection').first().toJSON());

                            self.updateValueString();
                        });
                    });
                }
            });
        </script>
        HTML;
    }

    public static function metaBoxToggler($hidden)
    {
        // die(var_dump($hidden));
        return <<<HTML
            <style>#{{group-name}} {display: none;}</style>
            <div id="rad-metabox-toggler-{{group-name}}"></div>
            <script>

                const elementsToHide = {{#json-encode hidden}}


                document.getElementById("metaBoxToggler{{group-name}}")
                function hideStuff(state, elementsToHide) {
                    // Store references to DOM elements
                    for (var i = 0; i < elementsToHide.length; i++) {
                        var element = elementsToHide[i];
                        if (element === "WYSIWYG") {
                            jQuery("#postdivrich").css("visibility", "hidden");
                            jQuery("#postdivrich").height(0);
                        }
                    }

                }
                jQuery(document).ready(function() {
                        jQuery("#{{group-name}}").show();
                        jQuery("#{{group-name}} input, #{{group-name}} textarea").each(function() {
                            jQuery(this).removeAttr("disabled");
                        });
                        hideStuff(true, elementsToHide);
                        return;

                        jQuery("#{{group-name}} input, #{{group-name}} textarea").each(function() {
                            jQuery(this).attr("disabled", "disabled");
                        });
                        jQuery("#{{group-name}}").hide();
                        // bind event to this vue app
                        jQuery("#page_template").on("change", function() {
                            self.selectedTemplate = jQuery("#page_template option:selected").text();
                        });
                        // initial state hydration
                        self.selectedTemplate = jQuery("#page_template option:selected").text();
                    // hideStuff(elementsToHide)
                });


                // Initialize the module
            </script>
        HTML;

    }
}
