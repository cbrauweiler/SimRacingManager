/* ============================================================
   SimRace Liga Manager – Admin JS
   ============================================================ */

// ---- Tab Switching ----------------------------------------
function adminTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    const tab   = document.querySelector(`.tab[data-tab="${tabName}"]`);
    const panel = document.getElementById('tab-' + tabName);
    if (tab)   tab.classList.add('active');
    if (panel) panel.classList.add('active');
}

// ---- Modal ------------------------------------------------
function openModal(id) {
    document.getElementById(id)?.classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(id) {
    document.getElementById(id)?.classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay')) {
        e.target.classList.remove('open');
        document.body.style.overflow = '';
    }
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open').forEach(m => {
            m.classList.remove('open');
        });
        document.body.style.overflow = '';
    }
});

// ---- Confirm Delete ----------------------------------------
function confirmDelete(message, callback) {
    if (confirm(message || 'Wirklich löschen?')) callback();
}

// ---- Color Sync (hex input ↔ color picker) ----------------
function syncColor(inputId, pickerId) {
    const input = document.getElementById(inputId);
    const picker = document.getElementById(pickerId);
    if (!input || !picker) return;
    input.addEventListener('input', () => { if (/^#[0-9a-fA-F]{6}$/.test(input.value)) picker.value = input.value; });
    picker.addEventListener('input', () => { input.value = picker.value; });
}

// ---- File Upload Preview ----------------------------------
function setupImagePreview(fileInputId, previewId, urlInputId) {
    const fileInput = document.getElementById(fileInputId);
    const preview   = document.getElementById(previewId);
    const urlInput  = document.getElementById(urlInputId);
    if (!fileInput) return;
    fileInput.addEventListener('change', function() {
        const file = this.files[0];
        if (!file) return;
        const reader = new FileReader();
        reader.onload = (e) => {
            if (preview) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
        };
        reader.readAsDataURL(file);
    });
}

// ---- Drag & Drop Upload Zone ------------------------------
function initDropZone(zoneId, inputId, onFileCallback) {
    const zone  = document.getElementById(zoneId);
    const input = document.getElementById(inputId);
    if (!zone || !input) return;

    zone.addEventListener('click', () => input.click());
    zone.addEventListener('dragover', e => { e.preventDefault(); zone.classList.add('drag'); });
    zone.addEventListener('dragleave', () => zone.classList.remove('drag'));
    zone.addEventListener('drop', e => {
        e.preventDefault();
        zone.classList.remove('drag');
        const file = e.dataTransfer.files[0];
        if (file && onFileCallback) onFileCallback(file);
    });
    input.addEventListener('change', () => {
        if (input.files[0] && onFileCallback) onFileCallback(input.files[0]);
    });
}

// ---- Result File Parser -----------------------------------
const POINTS_PRESETS = {
    f1:     [25, 18, 15, 12, 10, 8, 6, 4, 2, 1],
    top10:  [10, 9, 8, 7, 6, 5, 4, 3, 2, 1],
    simple: [20, 15, 12, 10, 8, 6, 4, 3, 2, 1],
};

function parseCSVResult(text) {
    const lines = text.trim().split('\n').filter(l => l.trim());
    if (!lines.length) return [];
    const isHeader = /pos|fahrer|driver|name|position/i.test(lines[0]);
    const start = isHeader ? 1 : 0;
    let headers = [];
    if (isHeader) {
        headers = lines[0].split(/,|;/).map(h => h.trim().toLowerCase().replace(/"/g, ''));
    }
    const getCol = (cols, ...keys) => {
        for (const k of keys) {
            const i = headers.indexOf(k);
            if (i >= 0) return (cols[i] || '').trim();
        }
        return '';
    };

    return lines.slice(start).map((line, i) => {
        const cols = line.split(/,|;/).map(c => c.trim().replace(/^"|"$/g, ''));
        if (isHeader) {
            return {
                position:   parseInt(getCol(cols, 'pos', 'position', 'platz') || String(i+1)),
                driverName: getCol(cols, 'fahrer', 'driver', 'name', 'pilot') || cols[1] || '',
                teamName:   getCol(cols, 'team', 'mannschaft') || cols[2] || '',
                laps:       parseInt(getCol(cols, 'runden', 'laps', 'lap') || cols[3]) || 0,
                totalTime:  getCol(cols, 'zeit', 'time', 'total') || cols[4] || '',
                gap:        getCol(cols, 'abstand', 'gap', 'interval') || cols[5] || '',
                fastestLap: getCol(cols, 'bestzeit', 'fastest', 'fl') || '',
                dnf:        /dnf|ret|aus/i.test(getCol(cols, 'status', 'dnf') || ''),
            };
        }
        return {
            position: parseInt(cols[0]) || i+1,
            driverName: cols[1] || '',
            teamName:   cols[2] || '',
            laps:       parseInt(cols[3]) || 0,
            totalTime:  cols[4] || '',
            gap:        cols[5] || '',
            fastestLap: '',
            dnf:        false,
        };
    }).filter(e => e.driverName);
}

function parseJSONResult(text) {
    try {
        const data = JSON.parse(text);
        let entries = [];
        // iRacing format
        if (data.SessionResults?.Results) {
            entries = data.SessionResults.Results.map((r, i) => ({
                position:   i + 1,
                driverName: r.displayName || r.name || r.DriverName || '',
                teamName:   r.TeamName || r.CarClass || '',
                laps:       r.lapsComplete || r.Laps || 0,
                totalTime:  msToTime(r.Time || 0),
                gap:        i === 0 ? '' : ('+' + msToTime(r.Interval || 0)),
                fastestLap: msToTime(r.BestLapTime || 0),
                dnf:        r.ReasonOut !== 'Running' && !!r.ReasonOut,
            }));
        }
        // ACC format
        else if (data.Result) {
            entries = data.Result.map((r, i) => ({
                position:   i + 1,
                driverName: (r.CurrentDriver?.FirstName || '') + ' ' + (r.CurrentDriver?.LastName || ''),
                teamName:   r.CarModel || '',
                laps:       r.LapCount || 0,
                totalTime:  msToTime(r.TotalTime || 0),
                gap:        i === 0 ? '' : '+' + msToTime((r.TotalTime || 0) - (data.Result[0]?.TotalTime || 0)),
                fastestLap: msToTime(r.BestLap || 0),
                dnf:        false,
            })).map(e => ({ ...e, driverName: e.driverName.trim() }));
        }
        // Generic array
        else if (Array.isArray(data)) {
            entries = data.map((r, i) => ({
                position:   r.position || r.pos || i + 1,
                driverName: r.driver || r.name || r.Name || '',
                teamName:   r.team || r.Team || '',
                laps:       r.laps || r.Laps || 0,
                totalTime:  r.time || r.Time || '',
                gap:        r.gap || r.Gap || '',
                fastestLap: r.fastestLap || '',
                dnf:        r.dnf || false,
            }));
        }
        return entries;
    } catch (e) {
        return [];
    }
}

function msToTime(ms) {
    if (!ms || ms < 0) return '';
    const totalSecs = Math.floor(ms / 1000);
    const mins = Math.floor(totalSecs / 60);
    const secs = totalSecs % 60;
    const millis = ms % 1000;
    return `${mins}:${String(secs).padStart(2,'0')}.${String(millis).padStart(3,'0')}`;
}

// ---- Result Upload Preview Renderer -----------------------
function renderResultPreview(entries, knownDrivers, knownTeams, pointsSystem) {
    const pts = pointsSystem || POINTS_PRESETS.f1;
    const table = document.getElementById('result-preview-table');
    if (!table) return;

    table.innerHTML = `
    <div class="result-preview">
    <table class="admin-table">
      <thead><tr>
        <th>Pos</th><th>Fahrer (Datei)</th><th>Zuordnung</th><th>Team</th>
        <th>Runden</th><th>Zeit/Gap</th><th>FL</th><th>Punkte</th><th>DNF</th>
      </tr></thead>
      <tbody>
        ${entries.map((e, i) => {
            const matchedDriver = knownDrivers.find(d =>
                d.name.toLowerCase() === e.driverName.toLowerCase().trim()
            );
            const matchedTeam = matchedDriver
                ? knownTeams.find(t => t.id == matchedDriver.team_id)
                : knownTeams.find(t => t.name.toLowerCase() === (e.teamName||'').toLowerCase().trim());
            const p = e.dnf ? 0 : (pts[i] || 0);
            return `<tr>
              <td class="pos-col ${i===0?'pos-1':i===1?'pos-2':i===2?'pos-3':''}">${e.position || i+1}</td>
              <td>${escHtml(e.driverName)}</td>
              <td>
                <select class="form-control form-control-sm driver-map-select" data-row="${i}" name="driver_id[${i}]">
                  <option value="">– Nicht zugeordnet –</option>
                  ${knownDrivers.map(d => `<option value="${d.id}" ${matchedDriver?.id == d.id ? 'selected':''}>${escHtml(d.name)}</option>`).join('')}
                </select>
              </td>
              <td>
                <select class="form-control form-control-sm" name="team_id[${i}]">
                  <option value="">–</option>
                  ${knownTeams.map(t => `<option value="${t.id}" ${matchedTeam?.id == t.id ? 'selected':''}><span style="color:${t.color}">${escHtml(t.name)}</span></option>`).join('')}
                </select>
              </td>
              <td>${e.laps || '–'}</td>
              <td class="gap-col">${i===0 ? escHtml(e.totalTime) : escHtml(e.gap)}</td>
              <td>${e.fastestLap ? `<span class="fl-badge">FL</span>` : ''}</td>
              <td><input type="number" class="form-control form-control-sm" name="points[${i}]" value="${p}" style="width:70px"/></td>
              <td><input type="checkbox" name="dnf[${i}]" ${e.dnf ? 'checked' : ''}/></td>
            </tr>
            <input type="hidden" name="position[${i}]"       value="${e.position||i+1}"/>
            <input type="hidden" name="driver_name_raw[${i}]" value="${escHtml(e.driverName)}"/>
            <input type="hidden" name="team_name_raw[${i}]"   value="${escHtml(e.teamName)}"/>
            <input type="hidden" name="laps[${i}]"            value="${e.laps||0}"/>
            <input type="hidden" name="total_time[${i}]"      value="${escHtml(i===0?e.totalTime:'')}"/>
            <input type="hidden" name="gap[${i}]"             value="${escHtml(i>0?e.gap:'')}"/>
            <input type="hidden" name="fastest_lap[${i}]"     value="${escHtml(e.fastestLap)}"/>
            `;
        }).join('')}
      </tbody>
    </table></div>
    <input type="hidden" name="entry_count" value="${entries.length}"/>`;
}

function escHtml(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// ---- Points row builder -----------------------------------
function buildPointsRows(pts) {
    const container = document.getElementById('points-rows');
    if (!container) return;
    container.innerHTML = pts.map((p, i) => `
        <div class="flex flex-center gap-1 mb-1">
          <div class="font-display font-bold text-muted" style="min-width:36px">P${i+1}</div>
          <input type="number" class="form-control" name="points[${i}]" value="${p}" min="0" style="max-width:100px"/>
          <button type="button" class="btn btn-danger btn-sm btn-icon" onclick="removePointsRow(this)">✕</button>
        </div>`).join('');
}

function addPointsRow() {
    const rows = document.querySelectorAll('#points-rows > div');
    const i = rows.length;
    const div = document.createElement('div');
    div.className = 'flex flex-center gap-1 mb-1';
    div.innerHTML = `<div class="font-display font-bold text-muted" style="min-width:36px">P${i+1}</div>
        <input type="number" class="form-control" name="points[${i}]" value="0" min="0" style="max-width:100px"/>
        <button type="button" class="btn btn-danger btn-sm btn-icon" onclick="removePointsRow(this)">✕</button>`;
    document.getElementById('points-rows').appendChild(div);
    rebuildPointsIndexes();
}
function removePointsRow(btn) { btn.closest('div').remove(); rebuildPointsIndexes(); }
function rebuildPointsIndexes() {
    document.querySelectorAll('#points-rows > div').forEach((div, i) => {
        div.querySelector('.text-muted').textContent = `P${i+1}`;
        div.querySelector('input').name = `points[${i}]`;
    });
}
function setPointsPreset(preset) {
    const pts = POINTS_PRESETS[preset];
    if (pts) buildPointsRows(pts);
}

// ---- Flash auto-dismiss -----------------------------------
setTimeout(() => {
    document.querySelectorAll('.flash-message, .notice').forEach(el => {
        el.style.transition = 'opacity .5s';
        el.style.opacity = '0';
        setTimeout(() => el.remove(), 500);
    });
}, 5000);

// ---- Sidebar active detection -----------------------------
(function() {
    const path = window.location.pathname;
    document.querySelectorAll('.admin-menu-item').forEach(item => {
        const href = item.getAttribute('href') || '';
        if (href && path.endsWith(href.split('/').pop())) {
            item.classList.add('active');
        }
    });
})();
