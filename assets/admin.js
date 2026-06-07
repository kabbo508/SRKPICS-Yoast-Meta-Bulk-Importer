(function($){
    let jobId = null;
    let offset = 0;
    let running = false;

    function log(message, type){
        const cls = type || 'info';
        const el = $('#srkpics-ymbi-log');
        el.append('<div class="'+cls+'">'+escapeHtml(message)+'</div>');
        el.scrollTop(el[0].scrollHeight);
    }

    function escapeHtml(text){
        return String(text).replace(/[&<>'"]/g, function(c){
            return {'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#039;','"':'&quot;'}[c];
        });
    }

    function setConnection(text, state){
        const el = $('.srkpics-ymbi-connection');
        el.removeClass('active error').addClass(state || '').html('<span></span> '+text);
    }

    function updateCounts(data){
        $('#ymbi-total').text(data.total || 0);
        $('#ymbi-processed').text(data.processed || 0);
        $('#ymbi-updated').text(data.updated || 0);
        $('#ymbi-skipped').text(data.skipped || 0);
        $('#ymbi-failed').text(data.failed || 0);
        const percent = data.total ? Math.min(100, Math.round((data.processed / data.total) * 100)) : 0;
        $('.srkpics-ymbi-progress div').css('width', percent + '%');
    }

    function processBatch(){
        if(!running || !jobId){return;}
        $.post(SRKPICS_YMBI.ajax_url, {
            action: 'srkpics_ymbi_process_batch',
            nonce: SRKPICS_YMBI.nonce,
            job_id: jobId,
            offset: offset,
            limit: SRKPICS_YMBI.batch_size || 20
        }).done(function(resp){
            if(!resp.success){
                setConnection(resp.data && resp.data.message ? resp.data.message : 'Import failed.', 'error');
                log(resp.data && resp.data.message ? resp.data.message : 'Import failed.', 'fail');
                running = false;
                return;
            }
            const data = resp.data;
            (data.logs || []).forEach(function(item){
                let type = item.indexOf('Updated') !== -1 ? 'ok' : item.indexOf('Skipped') !== -1 ? 'skip' : item.indexOf('Failed') !== -1 ? 'fail' : 'info';
                log(item, type);
            });
            offset = data.processed;
            updateCounts(data);
            if(data.done){
                running = false;
                setConnection('Import completed successfully.', 'active');
                log('Import completed.', 'ok');
            }else{
                setConnection('Import running, next batch processing...', 'active');
                setTimeout(processBatch, 500);
            }
        }).fail(function(){
            running = false;
            setConnection('AJAX connection failed.', 'error');
            log('AJAX connection failed. Please check server error logs.', 'fail');
        });
    }

    $('#srkpics-ymbi-form').on('submit', function(e){
        e.preventDefault();
        if(running){return;}
        const fileInput = $('#srkpics-ymbi-csv')[0];
        if(!fileInput.files.length){
            log('Please select a CSV file.', 'skip');
            return;
        }
        const formData = new FormData();
        formData.append('action', 'srkpics_ymbi_upload_csv');
        formData.append('nonce', SRKPICS_YMBI.nonce);
        formData.append('csv_file', fileInput.files[0]);

        $('#srkpics-ymbi-log').html('');
        setConnection('Uploading CSV...', 'active');
        $.ajax({
            url: SRKPICS_YMBI.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false
        }).done(function(resp){
            if(!resp.success){
                setConnection(resp.data && resp.data.message ? resp.data.message : 'Upload failed.', 'error');
                log(resp.data && resp.data.message ? resp.data.message : 'Upload failed.', 'fail');
                return;
            }
            jobId = resp.data.job_id;
            offset = 0;
            running = true;
            updateCounts({total: resp.data.total, processed: 0, updated: 0, skipped: 0, failed: 0});
            log(resp.data.message, 'ok');
            setConnection('Connected, processing batch by batch...', 'active');
            processBatch();
        }).fail(function(){
            setConnection('Upload connection failed.', 'error');
            log('Upload connection failed. Please try again.', 'fail');
        });
    });
})(jQuery);
