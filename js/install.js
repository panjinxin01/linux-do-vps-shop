// 安装向导前端逻辑
const API = '../api/install.php';
let currentStep = 1;
let generatedKey = '';

function $(id) { return document.getElementById(id); }

function showMsg(id, text, type) {
    const el = $(id);
    if (!el) return;
    el.textContent = text;
    el.className = 'msg-box ' + (type || '');
}

function clearMsg(id) {
    const el = $(id);
    if (!el) return;
    el.textContent = '';
    el.className = 'msg-box';
}

function setLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    if (loading) {
        btn.dataset.origHtml = btn.innerHTML;
        btn.innerHTML = '<span class="loading"></span>请稍候...';
    } else {
        btn.innerHTML = btn.dataset.origHtml || '提交';
    }
}

function goStep(step) {
    currentStep = step;
    document.querySelectorAll('.step-view').forEach(function(el) {
        el.classList.toggle('active', el.dataset.step == step);
    });
    // 更新步骤指示器
    document.querySelectorAll('.step-dot').forEach(function(dot, i) {
        dot.classList.remove('active', 'done');
        if (i + 1 < step) dot.classList.add('done');
        else if (i + 1 === step) dot.classList.add('active');
    });
}

function postApi(action, data) {
    var body = new FormData();
    body.append('action', action);
    if (data) {
        Object.keys(data).forEach(function(k) { body.append(k, data[k]); });
    }
    return fetch(API, { method: 'POST', body: body })
        .then(function(r) { return r.json(); });
}

function fetchInstallState() {
    return fetch('../api/check_install.php?_=' + Date.now(), { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(res) { return res && res.data ? res.data : {}; });
}

// ── DSN 解析：前端本地降级方案（浏览器 URL API） ──
function parseDsnLocally(dsn) {
    var result = { db_host: 'localhost', db_port: '3306', db_user: 'root', db_pass: '', db_name: 'vps_shop' };
    if (!dsn) return result;
    try {
        var url = new URL(dsn);
        if (url.protocol !== 'mysql:') return result;
        result.db_host = url.hostname || 'localhost';
        result.db_port = url.port || '3306';
        result.db_user = decodeURIComponent(url.username) || 'root';
        result.db_pass = decodeURIComponent(url.password) || '';
        result.db_name = url.pathname ? url.pathname.replace(/^\//, '').replace(/\/.*$/, '') : 'vps_shop';
    } catch (e) { /* 降级失败，返回默认值 */ }
    return result;
}

// ── DSN 解析按钮 ──
function parseDsn() {
    clearMsg('step1Msg');
    var dsn = $('dbDsn').value.trim();
    if (!dsn) {
        showMsg('step1Msg', '请先粘贴 MySQL 连接字符串', 'error');
        return;
    }
    var btn = $('btnParseDsn');
    setLoading(btn, true);

    // 优先调用后端 API 解析
    postApi('parse_dsn', { dsn: dsn }).then(function(res) {
        setLoading(btn, false);
        if (res.code === 1 && res.data) {
            applyDsnResult(res.data);
            showMsg('step1Msg', 'DSN 解析成功，已自动填充表单', 'success');
        } else {
            // 后端失败，尝试本地解析
            var local = parseDsnLocally(dsn);
            applyDsnResult(local);
            showMsg('step1Msg', res.msg || '解析完成（本地模式）', local.db_host !== 'localhost' ? 'success' : 'warning');
        }
    }).catch(function() {
        setLoading(btn, false);
        // 网络错误，降级到本地解析
        var local = parseDsnLocally(dsn);
        applyDsnResult(local);
        showMsg('step1Msg', '网络请求失败，已使用本地解析填充', local.db_host !== 'localhost' ? 'success' : 'warning');
    });
}

function applyDsnResult(d) {
    if (!d) return;
    $('dbHost').value = d.db_host || 'localhost';
    $('dbPort').value = d.db_port || '3306';
    $('dbUser').value = d.db_user || 'root';
    $('dbPass').value = d.db_pass || '';
    $('dbName').value = d.db_name || 'vps_shop';
}

// ── 获取表单数据（支持 DSN 优先） ──
function getDbFormData(includeDsn) {
    var data = {};
    var dsn = $('dbDsn').value.trim();
    if (includeDsn !== false && dsn) {
        data.db_dsn = dsn;
    } else {
        data.db_host = $('dbHost').value;
        data.db_port = $('dbPort').value;
        data.db_user = $('dbUser').value;
        data.db_pass = $('dbPass').value;
        data.db_name = $('dbName').value;
    }
    return data;
}

// 初始化：加载当前配置
function init() {
    fetch(API + '?action=get_config')
        .then(function(r) { return r.json(); })
        .then(function(res) {
            if (res.code === 1 && res.data) {
                var d = res.data;
                $('dbHost').value = d.DB_HOST || 'localhost';
                $('dbPort').value = d.DB_PORT || 3306;
                $('dbUser').value = d.DB_USER || 'root';
                $('dbPass').value = d.DB_PASS || '';
                $('dbName').value = d.DB_NAME || 'vps_shop';
            }
        })
        .catch(function() {});
    goStep(1);
}

// 步骤1：测试数据库连接
function testConnection() {
    clearMsg('step1Msg');
    var btn = $('btnTest');
    setLoading(btn, true);

    var data = getDbFormData();

    postApi('test_db', data).then(function(res) {
        setLoading(btn, false);
        showMsg('step1Msg', res.msg, res.code === 1 ? 'success' : 'error');
    }).catch(function() {
        setLoading(btn, false);
        showMsg('step1Msg', '请求失败，请检查网络', 'error');
    });
}

// 步骤1：保存配置并进入下一步
function saveAndNext() {
    clearMsg('step1Msg');
    var btn = $('btnSave');
    setLoading(btn, true);

    var data = getDbFormData();

    postApi('save_config', data).then(function(res) {
        setLoading(btn, false);
        if (res.code === 1) {
            goStep(2);
        } else {
            showMsg('step1Msg', res.msg, 'error');
        }
    }).catch(function() {
        setLoading(btn, false);
        showMsg('step1Msg', '请求失败', 'error');
    });
}

// 步骤2：生成加密密钥
function generateKey() {
    clearMsg('step2Msg');
    var btn = $('btnGenKey');
    setLoading(btn, true);

    postApi('generate_key', {}).then(function(res) {
        setLoading(btn, false);
        if (res.code === 1 && res.data) {
            generatedKey = res.data.key;
            $('keyDisplay').style.display = 'block';
            $('keyCode').textContent = generatedKey;
            $('btnGenKey').style.display = 'none';
            showMsg('step2Msg', res.msg, res.data.written ? 'success' : 'warning');
        } else {
            showMsg('step2Msg', res.msg || '生成失败', 'error');
        }
    }).catch(function() {
        setLoading(btn, false);
        showMsg('step2Msg', '请求失败', 'error');
    });
}

// 复制密钥
function copyKey() {
    var text = generatedKey || ($('keyCode') && $('keyCode').textContent) || '';
    if (!text) return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function() {
            alert('密钥已复制，请妥善保存！');
        }).catch(function() { fallbackCopy(text); });
    } else {
        fallbackCopy(text);
    }
}

function fallbackCopy(text) {
    var ta = document.createElement('textarea');
    ta.value = text;
    ta.style.position = 'fixed';
    ta.style.opacity = '0';
    document.body.appendChild(ta);
    ta.select();
    try {
        document.execCommand('copy');
        alert('密钥已复制，请妥善保存！');
    } catch (e) {
        alert('复制失败，请手动复制');
    }
    document.body.removeChild(ta);
}

// 步骤2 -> 步骤3
function goToInstall() {
    goStep(3);
}

// 步骤2 -> 步骤3（跳过加密密钥，提示用户）
function skipAndGoInstall() {
    var hasKey = $('keyDisplay') && $('keyDisplay').style.display === 'block';
    if (!hasKey) {
        showMsg('step2Msg', '未配置加密密钥，VPS 密码将以明文存储。你可以在后续的系统设置中补充配置。', 'warning');
    }
    goStep(3);
}

// 步骤3：执行安装
function runInstall() {
    clearMsg('step3Msg');
    var btn = $('btnInstall');
    setLoading(btn, true);

    postApi('run_install', {}).then(function(res) {
        if (res.code !== 1) {
            setLoading(btn, false);
            showMsg('step3Msg', res.msg, 'error');
            return;
        }
        goStep(4);
        fetchInstallState().then(function(state) {
            setLoading(btn, false);
            var hasAdmin = !!state.admin_ok || Number(state.admin_count || 0) > 0;
            var nextUrl = hasAdmin ? 'setup.html?existing_admin=1' : 'setup.html';
            var tip = document.querySelector('[data-step="4"] .subtitle');
            if (tip) {
                tip.textContent = hasAdmin
                    ? '检测到当前数据库中已存在管理员账号，正在跳转到确认页面...'
                    : '正在跳转到管理员创建页面...';
            }
            setTimeout(function() {
                window.location.replace(nextUrl);
            }, 1200);

        }).catch(function() {
            setLoading(btn, false);
            setTimeout(function() {
                window.location.replace('setup.html');
            }, 1200);
        });
    }).catch(function() {
        setLoading(btn, false);
        showMsg('step3Msg', '请求失败', 'error');
    });
}

// 页面加载
document.addEventListener('DOMContentLoaded', init);
