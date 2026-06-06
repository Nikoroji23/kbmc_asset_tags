(function(){
    var currentUserDetails = null;
    var currentUserAssets = [];
    function _esc(s){ if (s==null||s==='') return 'N/A'; return String(s); }

    function renderModalBody(body, user, assets){
        currentUserDetails = user;
        currentUserAssets = assets || [];
        var primaryIP='N/A', primaryPC='N/A';
        for(var i=0;i<assets.length;i++){ if(primaryIP==='N/A' && assets[i].ip_address && assets[i].ip_address!=='N/A') primaryIP=assets[i].ip_address; if(primaryPC==='N/A' && assets[i].pc_name && assets[i].pc_name!=='N/A') primaryPC=assets[i].pc_name; }
        currentUserDetails.pc_name = primaryPC;
        currentUserDetails.ip_address = primaryIP;
        var html = '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px;background:#f8f9fc;padding:16px;border-radius:10px;border:1px solid #e5e9f0;">'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">Employee ID</div><div style="font-size:13px;font-weight:600;">'+_esc(user.employee_id||user.id)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">Full Name</div><div style="font-size:13px;font-weight:600;">'+_esc(user.full_name)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">Email</div><div style="font-size:13px;">'+_esc(user.email)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">Department</div><div style="font-size:13px;">'+_esc(user.department)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">Position</div><div style="font-size:13px;">'+_esc(user.position)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">Status</div><div style="font-size:13px;">'+_esc(user.status)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">PC Name</div><div style="font-size:13px;color:#c0392b;font-weight:700;">'+_esc(primaryPC)+'</div></div>'
            + '<div><div style="font-size:10px;font-weight:700;color:#999;text-transform:uppercase;">IP Address</div><div style="font-size:13px;color:#c0392b;font-weight:700;">'+_esc(primaryIP)+'</div></div>'
            + '</div>';
        if(!assets || assets.length===0){
            html += '<div style="text-align:center;padding:30px;color:#999;"><i class="fas fa-box-open" style="font-size:28px;display:block;margin-bottom:8px;"></i>No devices currently assigned to this user.</div>';
        } else {
            html += '<div style="overflow-x:auto;border-radius:8px;border:1px solid #e5e9f0;"><table style="width:100%;border-collapse:collapse;font-size:13px;"><thead><tr style="background:#c0392b;color:#fff;font-size:11px;font-weight:700;text-transform:uppercase;"><th style="padding:9px 12px">Asset Tag</th><th style="padding:9px 12px">PC Name</th><th style="padding:9px 12px">IP Address</th><th style="padding:9px 12px">Type</th><th style="padding:9px 12px">Status</th><th style="padding:9px 12px">Assigned Date</th></tr></thead><tbody>';
            assets.forEach(function(a){ html += '<tr>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;"><strong>'+_esc(a.asset_tag)+'</strong></td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_esc(a.pc_name)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_esc(a.ip_address)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_esc(a.category || a.name)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_esc(a.status)+'</td>'
                + '<td style="padding:10px 12px;border-bottom:1px solid #f0f2f8;">'+_esc(a.assigned_at)+'</td>'
                + '</tr>'; });
            html += '</tbody></table></div>';
        }
        body.innerHTML = html;
    }

    function openGlobalModal(){ var m = document.getElementById('globalAssignedUserModal'); if(!m) return; m.style.display='flex'; }
    function closeGlobalModal(){ var m = document.getElementById('globalAssignedUserModal'); if(!m) return; m.style.display='none'; }

    function exportCurrentUserPDF(){
        if (!currentUserDetails) return;
        if (!window.jspdf || !window.jspdf.jsPDF) {
            alert('PDF library not loaded. Please refresh and try again.');
            return;
        }
        var doc = new window.jspdf.jsPDF({orientation:'portrait', unit:'pt', format:'a4'});
        var margin = 36;
        var y = 36;
        doc.setFontSize(16); doc.setTextColor(34,34,34); doc.setFont(undefined, 'bold');
        doc.text('KBMC Asset Management', margin, y);
        y += 20;
        doc.setFontSize(11); doc.setFont(undefined,'normal');
        doc.text('Employee Asset Details', margin, y);
        y += 18;
        doc.setFontSize(9); doc.setTextColor(120,120,120);
        doc.text('Generated: ' + new Date().toLocaleDateString('en-US',{year:'numeric',month:'long',day:'numeric'}), margin, y);
        y += 24;
        doc.setFillColor(248,249,252);
        doc.setDrawColor(224,224,224);
        doc.rect(margin, y, doc.internal.pageSize.getWidth() - margin*2, 88, 'F');
        var cellX = margin + 10;
        var cellY = y + 18;
        doc.setFontSize(10); doc.setTextColor(80,80,80);
        doc.text('Employee ID: ' + _esc(currentUserDetails.employee_id || currentUserDetails.id), cellX, cellY);
        doc.text('Full Name: ' + _esc(currentUserDetails.full_name), cellX + 260, cellY);
        cellY += 14;
        doc.text('Email: ' + _esc(currentUserDetails.email), cellX, cellY);
        doc.text('Department: ' + _esc(currentUserDetails.department), cellX + 260, cellY);
        cellY += 14;
        doc.text('Position: ' + _esc(currentUserDetails.position), cellX, cellY);
        doc.text('Status: ' + _esc(currentUserDetails.status), cellX + 260, cellY);
        cellY += 14;
        doc.text('PC Name: ' + _esc(currentUserDetails.pc_name || 'N/A'), cellX, cellY);
        doc.text('IP Address: ' + _esc(currentUserDetails.ip_address || 'N/A'), cellX + 260, cellY);
        y += 108;
        var rows = currentUserAssets.map(function(a){
            return [a.asset_tag||'N/A', a.pc_name||'N/A', a.ip_address||'N/A', a.category||a.name||'N/A', a.status||'N/A', a.assigned_at||'N/A'];
        });
        if (rows.length === 0) {
            doc.setFontSize(12); doc.setTextColor(120,120,120);
            doc.text('No assigned devices.', margin, y);
        } else {
            if (!doc.autoTable) {
                alert('PDF table library not loaded.'); return;
            }
            doc.autoTable({
                head: [['Asset Tag','PC Name','IP Address','Type','Status','Assigned Date']],
                body: rows,
                startY: y,
                styles:{fontSize:9,cellPadding:6},
                headStyles:{fillColor:[192,57,43],textColor:255,fontStyle:'bold'},
                alternateRowStyles:{fillColor:[248,249,252]},
                margin:{left:margin,right:margin}
            });
        }
        var name = (currentUserDetails.full_name || 'employee').replace(/[^a-z0-9\-_]/gi,'_');
        var dateSuffix = new Date().toISOString().slice(0,10);
        doc.save('employee_' + name + '_assets_' + dateSuffix + '.pdf');
    }

    document.addEventListener('click', function(e){
        var a = e.target.closest && e.target.closest('a[href]');
        if(!a) return;
        var href = a.getAttribute('href');
        if(!href) return;
        // intercept legacy it_user_details.php links
        if(href.indexOf('it_user_details.php') !== -1){
            e.preventDefault();
            try {
                var url = new URL(href, window.location.origin);
                var id = url.searchParams.get('id');
            } catch(err){
                var qs = href.split('?')[1]||''; var id = null; qs.split('&').forEach(function(p){ var kv = p.split('='); if(kv[0]==='id') id = kv[1]; });
            }
            if(!id) return;
            var body = document.getElementById('globalAssignedUserBody');
            if(body) body.innerHTML = '<p style="text-align:center;color:#999;padding:30px;"><i class="fas fa-spinner fa-spin"></i> Loading...</p>';
            openGlobalModal();
            fetch('api_user_details.php?view_user=' + encodeURIComponent(id), {headers:{'X-Requested-With':'XMLHttpRequest'}})
                .then(function(res){ if(!res.ok) throw new Error('HTTP '+res.status); return res.json(); })
                .then(function(data){ if(data.error) throw new Error(data.error); renderModalBody(body, data.user, data.assets || []); })
                .catch(function(err){ if(body) body.innerHTML = '<p style="text-align:center;color:#e74c3c;padding:30px;"><i class="fas fa-exclamation-circle"></i> '+(err.message||'Failed to load')+'</p>'; });
        }
    });

    // close button
    document.addEventListener('DOMContentLoaded', function(){
        var btn = document.getElementById('globalAssignedUserClose'); if(btn) btn.addEventListener('click', closeGlobalModal);
        var pdfBtn = document.getElementById('globalAssignedUserPDF'); if(pdfBtn) pdfBtn.addEventListener('click', exportCurrentUserPDF);
        document.addEventListener('keydown', function(e){ if(e.key==='Escape') closeGlobalModal(); });
    });
})();
