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
        btn.dataset.origText = btn.textContent;
        btn.innerHTML = '<span class="loading"></span>请稍候...';
    } else {
        btn.textContent = btn.dataset.origText || '提交';
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

// ===== DSN 解析 =====

/**
 * 解析 MySQL DSN 连接字符串并填充表单
 * 格式: mysql://user:pass@host:port/database
 */
function parseDsnString(dsn) {
    try {
        var url = new URL(dsn);
        if (url.protocol !== 'mysql:' && url.protocol !== 'mysqls:') {
            return false;
        }
        $('dbHost').value = url.hostname || 'localhost';
        $('dbPort').value = url.port || '3306';
        $('dbUser').value = decodeURIComponent(url.username) || 'root';
        $('dbPass').value = decodeURIComponent(url.password) || '';
        $('dbName').value = url.pathname.replace(/^\//, '') || '';
        return true;
    } catch(e) {
        return false;
    }
}

function parseDsnAndFill() {
    clearMsg('step1Msg');
    var dsn = $('dbDsn') ? $('dbDsn').value.trim() : '';
    if (!dsn) {
        showMsg('step1Msg', '请先粘贴 MySQL DSN 连接字符串', 'error');
        return;
    }
    if (parseDsnString(dsn)) {
        showMsg('step1Msg', '解析成功，字段已自动填充，建议先点击「测试连接」验证', 'success');
    } else {
        showMsg('step1Msg', 'DSN 格式无效，请使用 mysql://用户:密码@主机:端口/数据库 格式', 'error');
    }
}

function toggleManualConfig() {
    var el = $('manualConfig');
    if (!el) return;
    var isHidden = el.style.display === 'none';
    el.style.display = isHidden ? '' : 'none';
}

// =====

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

    postApi('test_db', {
        db_host: $('dbHost').value,
        db_port: $('dbPort').value,
        db_user: $('dbUser').value,
        db_pass: $('dbPass').value
    }).then(function(res) {
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

    postApi('save_config', {
        db_host: $('dbHost').value,
        db_port: $('dbPort').value,
        db_user: $('dbUser').value,
        db_pass: $('dbPass').value,
        db_name: $('dbName').value
    }).then(function(res) {
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
