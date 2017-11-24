/* requires jquery and moment.js */

    var RangeCalendar = function(config) {
        this.init.call(this, config);
    };

    RangeCalendar.prototype = {

        /* config  vars */
        pa: null,  /* to attach input-from input-to duration-days */
        from_inp: null,
        to_inp: null,
        dur_inp: null,
        month: moment().startOf('month'),
        callback: function(){}, /* returns values from inputs */
        /* ----------- */

        init: function(config){
            if( 'undefined' == typeof(config.pa) ){
                return;
            }
            if( 'undefined' != typeof(config.callback) ){
                this.callback = config.callback;
            }
            this.pa = config.pa;
            $('<span>From </span>').appendTo(this.pa);
            var cal_from = $('<div class="calendar-from">'
                + '<input class="calendar-date-inp" type="text" placeholder="MM-DD-YYYY"/>'
                + '<input class="calendar-time-inp" type="text" placeholder="hh:mm:ss" value="00:00:00"/></div>').appendTo(this.pa);
            $('<span> To </span>').appendTo(this.pa);
            var cal_to = $('<div class="calendar-to">'
                + '<input class="calendar-date-inp" type="text" placeholder="MM-DD-YYYY"/>'
                + '<input class="calendar-time-inp" type="text" placeholder="hh:mm:ss" value="00:00:00"/></div>').appendTo(this.pa);
            $('<span> Duration </span>').appendTo(this.pa);
            var cal_duration = $('<input class="calendar-duration-inp" type="text" placeholder="Y:M:D hh:mm:ss" value="0:0:0 00:00:00"/>')
                    .appendTo(this.pa);
            this.from = this.initMonthTable(cal_from, null, 'from'); 
            this.to = this.initMonthTable(cal_to, null, 'to'); 
            this.duration = { 
                'input': cal_duration,
                'duration': moment.duration(0)
             };
            this.bind();
        },

        bind: function(){
            var that = this;
            this.from.input.off('blur').on('blur', function(e){ that.onEndsInputChange.call(that, $(this), that.from); });
            this.from.time_input.off('blur').on('blur', function(e){ that.onEndsTimeChange.call(that, $(this), that.from); });
            this.to.input.off('blur').on('blur', function(e){ that.onEndsInputChange.call(that, $(this), that.to); });
            this.to.time_input.off('blur').on('blur', function(e){ that.onEndsTimeChange.call(that, $(this), that.to); });
            this.duration.input.off('blur').on('blur', function(e){ that.onDurationChange.call(that, $(this)); });
        },

        bind_month_table: function(tb_o){
            var that = this;
            tb_o.table.find('.month-prev').off('click').on('click', function(e){
                e.preventDefault();
                tb_o.month.subtract(1, 'month');
                that.setMonth( that[tb_o.type ] );
            });
            tb_o.table.find('.month-next').off('click').on('click', function(e){
                e.preventDefault();
                tb_o.month.add(1, 'month');
                that.setMonth( that[tb_o.type ] );
            });

            tb_o.table.find('td').off('click').on('click', function(e){
                e.preventDefault();
                var mom = moment( $(this).data('date'), 'Y-MM-DD HH:mm:ss' );
                if( !mom.isValid() ){ return; }

                tb_o.date = mom;
                if( !tb_o.time ){ tb_o.time = moment( mom.format('H:mm:ss'), 'H:mm:ss' ); }
                tb_o.input.val( mom.format("MM-DD-Y") );

                that.updateSelected.call(that, tb_o);
            });
        },

        updateSelected: function(td_o){
            if( !td_o.time || !td_o.date ){ return; }
            var mom = td_o.time.clone();
            td_o.selected = td_o.date.clone().set({
                                'hour':   mom.get('hour'),
                                'minute': mom.get('minute'),
                                'second': mom.get('second')
                            });
            this.onEndsChange();
        },

        onEndsInputChange: function(inp, td_o){
            var date_str = inp.val();
            var mom = moment( date_str, "MM-DD-Y" );
            if( !mom.isValid() ){ return; }
            inp.val(mom.format( 'MM-DD-Y' ));

            td_o.date = mom;
            this.updateSelected(td_o);
        },

        onEndsTimeChange: function(inp, td_o){
            var time_str = inp.val();
            var mom = moment( time_str, 'H:mm:ss' );
            if( !mom.isValid() ){ return; }
            inp.val(mom.format( 'H:mm:ss' ));

            td_o.time = mom;
            this.updateSelected(td_o);
        },

        swapEnds: function(){
              var momTo =  this.from.selected.clone();
              var momFrom =  this.to.selected.clone();
              this.setDateTime( momTo, this.to);
              this.setDateTime( momFrom, this.from);
        },

        onEndsChange: function(){
          if( !this.to.selected || !this.from.selected ){ return; }
          if( this.to.selected.isBefore( this.from.selected ) ){
              this.swapEnds();
          }

          var diff =  (this.to.selected).diff(this.from.selected);
          this.duration.duration = moment.duration(diff); 
          var du = this.duration.duration;
          this.duration.input.val( du.years() + ':' + du.months() + ':' + du.days() + " " + du.hours() + ':' + du.minutes() + ':' + du.seconds() );

          this.highlightRange(this.from);
          this.highlightRange(this.to);
          this.fireCallback();
        },

        onDurationChange: function(inp){
            var arr = inp.val().split(/:|\s+/);
            console.log(arr);
            if( arr.length< 6 ){ return }
            var du = moment.duration({
                'year':   arr[0],
                'month': arr[1],
                'day': arr[2],
                'hour':   arr[3],
                'minute': arr[4],
                'second': arr[6]
            });
            if( !du.isValid() ){ return }
            this.duration.duration = du;
            this.duration.input.val( du.years() + ':' + du.months() + ':' + du.days() + " " + du.hours() + ':' + du.minutes() + ':' + du.seconds() );
            
            if( this.from.selected ){
                var mom = this.from.selected.clone();
                mom = mom.add(du);
                this.setDateTime( mom, this.to);
            }else if( this.to.selected ){
                var mom = this.to.selected.clone();
                mom = mom.subtract(du);
                this.setDateTime( mom, this.from);
            }            

          this.highlightRange(this.from);
          this.highlightRange(this.to);
          this.fireCallback();
        },

        fireCallback: function(){
            if( !this.to || !this.from || !this.to.selected || !this.from.selected ){ return; }
            this.callback({ 
                'from': this.from.selected.clone(), 
                'to': this.from.selected.clone(), 
                'duration': this.duration.duration });
        },

        setDateTime: function(mom, td_o){
            td_o.selected = mom;
            td_o.date = moment( mom.format('Y-MM-DD' ), 'Y-MM-DD');
            td_o.time = moment( mom.format('H:mm:ss'), 'H:mm:ss');
            td_o.input.val(mom.format("MM-DD-Y"));
            td_o.time_input.val(mom.format("H:mm:ss"));
        },

        highlightRange: function(td_o){
            var mom1 = this.from.selected.format('x');
            var mom2 = this.to.selected.format('x');
            var ts1 = Math.min(mom1, mom2); //since it's border of the day
            var ts2 = Math.max(mom1, mom2);
            var tds = td_o.table.find('td');
            tds.removeClass('data-in-range');
            tds.each(function(i,o){
                var ts = Number( $(o).data('ts') );
                if( ts1<=ts && ts<=ts2 ){ $(o).addClass('data-in-range'); }
                if( ts1==ts ){ $(o).addClass('range-start'); }
                if( ts==ts2 ){ $(o).addClass('range-end'); }
            });
        },

        setMonth: function(tb_o){
            tb_o.tbody.empty();
            tb_o.tbody.append( this.getMonthRows( tb_o.month ) );
            tb_o.header.html( tb_o.month.format("MMMM") );
            this.bind_month_table(tb_o);

            if( !this.to || !this.from || !this.to.selected || !this.from.selected ){ return; }
            this.highlightRange(this.from);
            this.highlightRange(this.to);
        },


        initMonthTable: function( holder, date, type ){
            if( !date ){ 
                date = moment(); 
                var selected = null;
                var sdate = null;
                var stime = null;
            }else{
                var selected = date;
                var sdate = moment( date.format('Y-MM-DD'), 'Y-MM-DD' );
                var stime = moment( date.format('H:mm:ss'), 'H:mm:ss' );
            }
            var tb = $( this.getTableFrame() ).appendTo( holder );
            tb.find('.month-prev, .month-next').attr('data-type', type);
            var tb_o = { 
                        'type': type,
                        'selected': selected,
                        'time': sdate,
                        'date': stime,
                        'month': date.startOf('month'),
                        'input': holder.find('input.calendar-date-inp'),
                        'time_input': holder.find('input.calendar-time-inp'),
                        'table':  tb,
                        'header': tb.find( '.calendar-month-header' ),
                        'tbody':  tb.find( 'tbody' )
                        };
            this.setMonth( tb_o );
        return tb_o;
        },

        getTableFrame: function(){
            var table = $('<table>\
                    <thead>\
                        <tr>\
                            <th colspan="7">\
                                <a href="#" class="month-arrow month-prev"></a>\
                                <span class="calendar-month-header"></span>\
                                <a href="#" class="month-arrow month-next"></a>\
                            </th>\
                        </tr>\
                        <tr class="week-header">' + this.getWeekHeaderThs() + '</tr>\
                    </thead>\
                    <tbody></tbody>\
                    </table>');
        return table;
        },

        getWeekHeaderThs: function(){
            var ths = '';
            var day = moment().startOf('week');
            for( var i=0; i<7; i++ ){
                ths += '<th>' + day.format('dd') + '</th>';
                day.add(1, 'days');
            }
        return ths;
        },

        getMonthRows: function( month ){ /* month is moment() of the beginning */
            var monthInd = month.format("M");
            var today_str = moment().format( 'Y-MM-DD' );
            var rows = '';
            var day = month.clone().startOf('week');
            for( var w=0; w<5; w++ ){
                rows += '<tr>';
                for( var i=0; i<7; i++ ){
                    var classes = ( day.format("M") != monthInd )? " not-in-month " : "";
                    classes += ( day.format("d") == 0 || day.format("d") == 6 )? " weekend " : "";
                    classes += ( day.format( 'Y-MM-DD' ) == today_str )? " today " : "";
                    //rows += '<td class="' + classes + '">' +  day.format("Do") + '</td>'; // with 3rd, 4th
                    rows += '<td class="' + classes + '" data-ts="' + day.format('x')  // for faster highlight
                             + '" data-date="' + day.format( 'Y-MM-DD HH:mm:ss' )+ '">' +  day.format("D") + '</td>';
                    day.add(1, 'days');
                }
                rows += '</tr>';
            }
        return rows;
        },

        destroy: function(){
        }

    };


