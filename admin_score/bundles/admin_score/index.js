window.onload = function(e){

    $('.db_table input').each(toggleColumn);
    $('td input').removeAttr('checked');
    $('input.select_all').removeAttr('checked');
    
    $('.select_all').change(function(e){
        if( $('td.id').length<1 ){ return; }

        if( !$(this).is(':checked') ){
            $('td.select_row input').each(function(i,o){ 
                $(o).removeAttr('checked'); 
                $(o).closest('tr').toggleClass('highlight', false);
            });
        }else{
            $('td.select_row input').each(function(i,o){ 
                $(o).prop('checked', true); 
                $(o).closest('tr').toggleClass('highlight', true);
            });
        }
    });
    
    $('.expand-btn').click(function(e){
        e.preventDefault();
        $(this).closest('li').toggleClass('open');
    });
    
    $('.db_table input').on('change', toggleColumn);
    
    $('.edit').click(activate_edit);
    $('.cancel-edit').click(cancel_edit);
    $('.save').click(submit_save);
    $('.refresh').click(submit_refresh);
    $('.delete_rows').click(submit_delete);

    $('td input').change(function(e){
        var row = $(this).closest('tr');
        if( row.find('td.id').length>0 ){
            row.toggleClass('highlight', $(this).is(':checked'));
        }
    });

};

var flashCard = {
    dismiss: function(){
        var this_card = $(this);
        this_card.addClass( 'way-to-the-right' );
        setTimeout(function(){ this_card.remove(); }, 500 );
    },

    add: function( type, note ){
       var box = $('.flash-box');
       var card  = $(
         '<div class="flash-card way-below ' + type + '">\
            <div class="flash-card-icon"><i class="icon3-flash"></i></div>\
            <div class="flash-card-text">' + note + '</div>\
         </div>').appendTo( box );
       setTimeout(function(){ card.removeClass('way-below'); }, 100 );
       card.bind('click', flashCard.dismiss);
       setTimeout(function(){ card.fadeOut(600, function(){ card.remove(); }) }, 45000 );
    }
};

function toggleColumn(){
    var li = $(this).closest('li.db_table');
    var table_name = li.children('a').text().trim();
    var col_name = $(this).next().find('.col_name').text().trim().replace(' ','_');
    var col_class = '.' + table_name + ' .' + col_name;
    $( col_class ).toggle( $(this).is(':checked') );
}

function activate_edit(e){
    e.preventDefault();
    $('.edit').addClass('hidden');
    $('.edit-save-span').removeClass('hidden');
    $('.my_table td').each(function(i,o){
        if( $(o).hasClass('select_row') || $(o).hasClass('id') || $(o).hasClass('fkey') ){
            // do nothing, just skip
        }else if( $(o).find('.thumbnail').length > 0 ){
            o.savedThumb = $(o).html();
            $(o).html( $(o).data('value') );
            $(o).prop('contenteditable', true);
        }else{
            $(o).prop('contenteditable', true);
        }
    });
}

function cancel_edit(e){
    e.preventDefault();
    $('.edit').removeClass('hidden');
    $('.edit-save-span').addClass('hidden');
    $('.my_table td').each(function(i,o){
        $(o).removeAttr('contenteditable');
        if( 'undefined' != typeof( o.savedThumb ) ){
            $(o).html( o.savedThumb );
        }
    });
}

function submit_refresh(){
    $.post('', 'refresh_prefs_table=1' )
     .done( function(r){
        console.log(r);
        flashCard.add('success', JSON.stringify(r) );
    });
}
function submit_save(){
    $.post('', $('form.prefs').serialize() )
     .done( function(r){
        console.log(r);
        flashCard.add('success', JSON.stringify(r) );
    });
}

function submit_delete(){
    $.post('', $('form.my_table').serialize() )
     .done( function(r){
        $('form.my_table tr.highlight').remove();
        console.log(r);
        flashCard.add('danger', JSON.stringify(r) );
    });
}

function onNewQuery(col, query_type, val){
    var params = getUrlParams();

    if( 'sort' == query_type ){
    }else if( 'equal' == query_type ){
    }else if( 'like' == query_type ){
    }else if( 'range' == query_type ){
    }
}

function getUrlParams(){
    var queryDict = {};
    location.search.substr(1).split("&")
        .forEach(function(item) {
            var arr = item.split("=");
            queryDict[arr[0]] = arr[1];
        });
return queryDict;
}
