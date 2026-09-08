/**
 * 应用商店 JavaScript 增强（替换 store.php 中的 <script> 部分）
 *
 * 改进点：
 * 1. 加载状态管理
 * 2. 抽屉式详情面板
 * 3. 骨架屏加载
 * 4. 搜索防抖
 * 5. 更好的错误提示
 */

(function() {
  "use strict";

  const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const msg = document.getElementById("storeMsg");
  const catError = document.getElementById("storeCatError");

  // ========== Toast 通知 ==========
  function showToast(text, type = "success") {
    if (typeof window.pafishToast === "function") {
      window.pafishToast(text, type);
      return;
    }
    // 降级方案
    if (msg) {
      msg.textContent = text;
      msg.className = "admin-backup-msg " + (type === "error" ? "admin-backup-msg-error" : "admin-backup-msg-ok");
      msg.hidden = false;
      setTimeout(() => { msg.hidden = true; }, 5000);
    }
  }

  // ========== 持久化消息（跨页面） ==========
  function persistMsg(text, isError) {
    try {
      sessionStorage.setItem("storeMsg", JSON.stringify({ t: text, e: isError ? 1 : 0 }));
    } catch (e) {}
  }

  // 恢复持久化消息
  try {
    const saved = sessionStorage.getItem("storeMsg");
    if (saved) {
      sessionStorage.removeItem("storeMsg");
      const m = JSON.parse(saved);
      showToast(m.t, m.e ? "error" : "success");
    }
  } catch (e) {}

  // ========== HTTP 工具 ==========
  function post(url, fd) {
    return fetch(url, {
      method: "POST",
      body: fd,
      headers: { "X-Requested-With": "XMLHttpRequest" }
    })
      .then(r => r.json())
      .then(j => {
        if (j && j.ok) return j;
        throw new Error((j && j.error) || "操作失败");
      });
  }

  // ========== 按钮加载状态 ==========
  function setButtonLoading(btn, loading, text = "") {
    if (loading) {
      btn.disabled = true;
      btn.dataset.originalText = btn.textContent;
      btn.classList.add("is-loading");
      btn.textContent = text || btn.textContent;
    } else {
      btn.disabled = false;
      btn.classList.remove("is-loading");
      btn.textContent = btn.dataset.originalText || btn.textContent;
    }
  }

  // ========== 刷新目录 ==========
  const refreshBtn = document.getElementById("storeRefresh");
  if (refreshBtn) {
    refreshBtn.addEventListener("click", function() {
      setButtonLoading(refreshBtn, true, "正在刷新");
      const fd = new FormData();
      fd.append("csrf_token", CSRF);
      post("/admin/store/refresh", fd)
        .then(() => {
          persistMsg("✓ 已刷新官方应用目录", false);
          location.reload();
        })
        .catch(err => {
          showToast(err.message, "error");
          setButtonLoading(refreshBtn, false);
        });
    });
  }

  // ========== Tab 切换 ==========
  const tabs = document.querySelectorAll(".admin-tab");
  const panes = document.querySelectorAll(".admin-store-pane");

  tabs.forEach(tab => {
    tab.addEventListener("click", function() {
      tabs.forEach(t => t.classList.remove("is-active"));
      panes.forEach(p => p.hidden = true);
      tab.classList.add("is-active");
      const pane = document.querySelector(`.admin-store-pane[data-pane="${tab.dataset.kind}"]`);
      if (pane) pane.hidden = false;
      // 重置搜索和筛选
      applyStoreFilters();
    });
  });

  // ========== 搜索 + 分类筛选 ==========
  const search = document.getElementById("storeSearch");
  const catSelect = document.getElementById("storeCategory");
  let searchTimer = null;

  function applyStoreFilters() {
    const q = search ? search.value.trim().toLowerCase() : "";
    const cat = catSelect ? catSelect.value : "";

    const activePane = document.querySelector(".admin-store-pane:not([hidden])");
    if (!activePane) return;

    const cards = activePane.querySelectorAll(".admin-theme-card");
    let visibleCount = 0;

    cards.forEach(card => {
      const matchQ = q === "" || (card.dataset.search || "").indexOf(q) !== -1;
      const matchCat = cat === "" || card.dataset.category === cat;
      const isVisible = matchQ && matchCat;

      card.style.display = isVisible ? "" : "none";
      if (isVisible) {
        visibleCount++;
        // 搜索高亮动画
        if (q !== "") {
          card.dataset.searchMatched = "true";
          setTimeout(() => delete card.dataset.searchMatched, 300);
        }
      }
    });

    // 显示无结果提示
    let emptyHint = activePane.querySelector(".admin-store-empty");
    if (visibleCount === 0 && !emptyHint) {
      emptyHint = document.createElement("div");
      emptyHint.className = "admin-store-empty";
      emptyHint.innerHTML = `
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <circle cx="11" cy="11" r="8"></circle>
          <path d="m21 21-4.35-4.35"></path>
        </svg>
        <p>没有找到匹配的应用</p>
      `;
      activePane.querySelector(".admin-theme-grid")?.after(emptyHint);
    } else if (visibleCount > 0 && emptyHint) {
      emptyHint.remove();
    }
  }

  // 防抖搜索
  if (search) {
    search.addEventListener("input", () => {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(applyStoreFilters, 300);
    });
  }

  if (catSelect) {
    catSelect.addEventListener("change", applyStoreFilters);
  }

  // ========== 详情抽屉 ==========
  function openDrawer(card) {
    let item;
    try {
      item = JSON.parse(card.dataset.storeItem || "{}");
    } catch (e) {
      item = {};
    }

    const kind = card.dataset.storeKind === "plugin" ? "插件" : "主题";
    const drawer = createDrawer(item, kind, card);
    document.body.appendChild(drawer);

    // 强制重排后显示（触发动画）
    requestAnimationFrame(() => {
      drawer.hidden = false;
    });
  }

  function createDrawer(item, kind, sourceCard) {
    const drawer = document.createElement("div");
    drawer.className = "admin-drawer";
    drawer.id = "storeDetailDrawer";

    const shots = Array.isArray(item.screenshots) ? item.screenshots : [];
    const gallery = shots.length ?
      '<div class="admin-store-detail-gallery">' +
      shots.map(src => {
        const imageUrl = safeUrl(src);
        return imageUrl ? `<img src="${esc(imageUrl)}" alt="" loading="lazy">` : '';
      }).join('') +
      '</div>' : '';

    const status = item.installed ?
      (item.updateAvailable ?
        `已安装 v${item.localVersion}，有新版本可用` :
        `已安装 v${item.localVersion}，当前为最新`) :
      '尚未安装';

    const purchase = item.paid ? (item.purchased ? '已购买' : '需要购买') : '免费';

    drawer.innerHTML = `
      <div class="admin-drawer-overlay" data-drawer-close></div>
      <div class="admin-drawer-panel">
        <div class="admin-drawer-head">
          <div>
            <h2>${esc(item.title || item.name || '应用详情')}</h2>
            <p>${kind} · v${esc(item.version || '未知')}${item.author ? ' · ' + esc(item.author) : ''}</p>
          </div>
          <button class="admin-icon-btn" data-drawer-close aria-label="关闭">×</button>
        </div>
        <div class="admin-drawer-body">
          ${gallery}
          <p class="admin-store-detail-description">${esc(item.description || '该条目未提供描述')}</p>
          <dl class="admin-store-detail-facts">
            <div><dt>作者</dt><dd>${esc(item.author || '未知')}</dd></div>
            <div><dt>版本</dt><dd>v${esc(item.version || '未知')}</dd></div>
            <div><dt>PHP 要求</dt><dd>${esc(item.requiresPhp || '未提供')}</dd></div>
            <div><dt>应用依赖</dt><dd>${esc(Array.isArray(item.requires) && item.requires.length ? item.requires.join('、') : '无')}</dd></div>
            <div><dt>安装包</dt><dd>${formatSize(item.packageSize)}</dd></div>
            <div><dt>发布时间</dt><dd>${esc(item.publishedAt || '未提供')}</dd></div>
            <div><dt>权益</dt><dd>${esc(purchase)}</dd></div>
            <div><dt>状态</dt><dd>${esc(status)}</dd></div>
          </dl>
          ${item.changelog ? '<div class="admin-store-detail-log"><h3>更新日志</h3><p>' + esc(item.changelog) + '</p></div>' : ''}
        </div>
        <div class="admin-drawer-actions">
          ${safeUrl(item.homepage) ? `<a class="btn btn-ghost" href="${esc(safeUrl(item.homepage))}" target="_blank" rel="noopener">查看官网</a>` : ''}
          <button class="btn btn-ghost" data-drawer-close>关闭</button>
        </div>
      </div>
    `;

    // 关闭事件
    drawer.querySelectorAll("[data-drawer-close]").forEach(el => {
      el.addEventListener("click", () => {
        drawer.hidden = true;
        setTimeout(() => drawer.remove(), 300);
      });
    });

    // 复制操作按钮
    const action = sourceCard.querySelector(".admin-store-install, .admin-store-update");
    if (action) {
      const actions = drawer.querySelector(".admin-drawer-actions");
      const clonedBtn = action.cloneNode(true);
      actions.insertBefore(clonedBtn, actions.lastElementChild);

      // 重新绑定事件
      if (clonedBtn.classList.contains("admin-store-install")) {
        bindInstallHandler(clonedBtn);
      } else if (clonedBtn.classList.contains("admin-store-update")) {
        bindUpdateHandler(clonedBtn);
      }
    }

    return drawer;
  }

  // 绑定详情按钮
  document.querySelectorAll(".admin-store-detail").forEach(btn => {
    btn.addEventListener("click", function() {
      openDrawer(btn.closest(".admin-theme-card"));
    });
  });

  // ========== 安装处理 ==========
  function bindInstallHandler(btn) {
    btn.addEventListener("click", function() {
      const needsPurchase = btn.dataset.paid === "1" && btn.dataset.purchased !== "1";
      const ask = needsPurchase ?
        "当前账号尚未显示购买记录，安装可能会被官方商城拒绝。" :
        "应用将下载并写入站点的主题或插件目录。";
      const detail = needsPurchase ?
        "如已完成购买，请先确认后台绑定的是对应的官方账号。" :
        "安装完成后可在主题或插件管理中启用。";

      const confirmPromise = window.pafishConfirm ?
        window.pafishConfirm(ask, {
          title: "安装应用 · " + btn.dataset.title,
          accept: "确认安装",
          danger: false,
          detail
        }) :
        Promise.resolve(window.confirm(ask));

      confirmPromise.then(ok => {
        if (!ok) return;

        setButtonLoading(btn, true, "正在安装");
        const fd = new FormData();
        fd.append("kind", btn.dataset.kind);
        fd.append("name", btn.dataset.name);
        fd.append("csrf_token", CSRF);

        return post("/admin/store/install", fd)
          .then(j => {
            const guide = btn.dataset.kind === "plugin" ?
              "，可到「插件管理」中启用" :
              "，可到「主题与外观」中启用";
            persistMsg(`✓ 已安装 ${j.title} v${j.version}${guide}`, false);
            location.reload();
          })
          .catch(err => {
            showToast(err.message, "error");
            setButtonLoading(btn, false);
          });
      });
    });
  }

  document.querySelectorAll(".admin-store-install").forEach(bindInstallHandler);

  // ========== 更新处理 ==========
  function bindUpdateHandler(btn) {
    btn.addEventListener("click", function() {
      const text = `将已安装的 ${btn.dataset.title} 从 v${btn.dataset.localVersion || "当前版本"} 更新到 v${btn.dataset.version}？`;
      const log = btn.dataset.changelog || "";
      const detail = "更新失败会自动恢复旧版本。" + (log ? "\n\n更新内容：\n" + log : "");

      const confirmPromise = window.pafishConfirm ?
        window.pafishConfirm(text, {
          title: "更新应用",
          accept: "确认更新",
          danger: false,
          detail
        }) :
        Promise.resolve(window.confirm(text + "\n" + detail));

      confirmPromise.then(ok => {
        if (!ok) return;

        setButtonLoading(btn, true, "正在更新");
        const fd = new FormData();
        fd.append("kind", btn.dataset.kind);
        fd.append("name", btn.dataset.name);
        fd.append("csrf_token", CSRF);

        return post("/admin/store/update", fd)
          .then(j => {
            persistMsg(`✓ 已更新 ${j.title} 到 v${j.version}`, false);
            location.reload();
          })
          .catch(err => {
            showToast(err.message, "error");
            setButtonLoading(btn, false);
          });
      });
    });
  }

  document.querySelectorAll(".admin-store-update").forEach(bindUpdateHandler);

  // ========== 工具函数 ==========
  function formatSize(bytes) {
    bytes = Number(bytes || 0);
    if (!bytes) return "未提供";
    if (bytes < 1024) return bytes + " B";
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + " KB";
    return (bytes / 1024 / 1024).toFixed(2) + " MB";
  }

  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, ch => ({
      "&": "&amp;",
      "<": "&lt;",
      ">": "&gt;",
      '"': "&quot;",
      "'": "&#39;"
    }[ch]));
  }

  function safeUrl(value) {
    const url = String(value || "");
    return /^https?:\/\//i.test(url) ? url : "";
  }
})();
