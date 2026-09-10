(function () {
  "use strict";
  window.PAFISH_EDITOR_EXTENSIONS = window.PAFISH_EDITOR_EXTENSIONS || [];

  var COMMON_KEYS = ["lumina_location", "lumina_location_address", "lumina_location_city", "lumina_location_poi_id", "lumina_location_lat", "lumina_location_lng", "lumina_private"];
  var TYPE_KEYS = {
    img: ["lumina_photos"],
    live: ["lumina_photos", "lumina_live_photos"],
    video: ["lumina_video_url", "lumina_video_poster"],
    embed: ["lumina_embed_url", "lumina_embed_ratio", "lumina_embed_cover"],
    music: ["lumina_music_url", "lumina_music_title", "lumina_music_artist", "lumina_music_cover"],
    link: ["lumina_link_url", "lumina_link_title", "lumina_link_desc", "lumina_link_image"],
    redpacket: ["redpacket_mode", "redpacket_total", "redpacket_count", "redpacket_title"]
  };
  var KEYS = ["lumina_type"].concat(COMMON_KEYS, Object.keys(TYPE_KEYS).reduce(function (all, type) { return all.concat(TYPE_KEYS[type]); }, []));
  var LABELS = {
    lumina_photos: "图片列表", lumina_live_photos: "实况图视频", lumina_video_url: "视频地址", lumina_video_poster: "视频封面",
    lumina_embed_url: "平台视频", lumina_embed_ratio: "平台视频方向", lumina_embed_cover: "平台视频封面",
    lumina_music_url: "音乐地址", lumina_music_title: "音乐标题", lumina_music_artist: "音乐作者", lumina_music_cover: "音乐封面",
    lumina_link_url: "链接地址", lumina_link_title: "链接标题", lumina_link_desc: "链接描述", lumina_link_image: "链接缩略图",
    lumina_location: "地点名称", lumina_location_address: "地点地址", lumina_location_city: "所在城市", lumina_location_poi_id: "地点 POI ID", lumina_location_lat: "纬度", lumina_location_lng: "经度",
    lumina_private: "可见范围", redpacket_mode: "红包类型", redpacket_total: "红包总积分", redpacket_count: "红包数量", redpacket_title: "红包标题"
  };
  function esc(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
    });
  }
  function input(key, value) {
    var long = ["lumina_photos", "lumina_live_photos", "lumina_embed_url", "lumina_link_desc"].indexOf(key) !== -1;
    var notes = {
      lumina_photos: "每行一张图片地址，也支持逗号分隔",
      lumina_live_photos: "每行一个视频地址，按图片顺序对应；也可写 图片地址|图片地址",
      lumina_embed_url: "支持 Bilibili、YouTube，或受支持平台的官方 iframe 代码",
      lumina_link_url: "以 http:// 或 https:// 开头的链接地址",
      lumina_link_title: "不填则卡片标题显示链接域名",
      lumina_location_lat: "填写经纬度后，地点可跳转到腾讯地图",
      lumina_location_lng: "填写经纬度后，地点可跳转到腾讯地图"
    }[key] || "";
    var control;
    if (key === "lumina_embed_ratio") {
      control = '<select class="input" data-lumina-key="' + key + '"><option value="lr"' + (value !== "tb" ? " selected" : "") + '>横屏 16:9</option><option value="tb"' + (value === "tb" ? " selected" : "") + '>竖屏 9:16</option></select>';
    } else if (key === "lumina_private") {
      control = '<select class="input" data-lumina-key="' + key + '"><option value="n"' + (value !== "y" ? " selected" : "") + '>公开</option><option value="y"' + (value === "y" ? " selected" : "") + '>仅自己可看</option></select>';
    } else if (key === "redpacket_mode") {
      control = '<select class="input" data-lumina-key="' + key + '"><option value="random"' + (value !== "equal" ? " selected" : "") + '>随机</option><option value="equal"' + (value === "equal" ? " selected" : "") + '>等额</option></select>';
    } else if (long) {
      control = '<textarea class="input" data-lumina-key="' + key + '" rows="3" maxlength="500">' + esc(value) + '</textarea>';
    } else {
      control = '<input class="input" data-lumina-key="' + key + '" maxlength="500" value="' + esc(value) + '">';
    }
    return '<label class="admin-lumina-field"><span>' + esc(LABELS[key] || key) + '</span>' + control + (notes ? '<small>' + esc(notes) + '</small>' : '') + '</label>';
  }
  window.PAFISH_EDITOR_EXTENSIONS.push({
    keys: KEYS,
    values: {},
    root: null,
    filterCustomFields: function (fields) {
      return (fields || []).filter(function (field) { return KEYS.indexOf(String(field.key || "")) === -1; });
    },
    init: function (ctx) {
      this.root = document.getElementById("luminaFields");
      if (!this.root) return;
      (ctx.initial.customFields || []).forEach(function (field) {
        if (KEYS.indexOf(String(field.key || "")) !== -1) this.values[field.key] = String(field.value || "");
      }, this);
      this.render();
    },
    render: function () {
      if (!this.root) return;
      var self = this;
      var type = this.values.lumina_type || "only";
      var fields = (TYPE_KEYS[type] || []).concat(COMMON_KEYS);
      var types = [["only", "纯文字"], ["img", "图文"], ["live", "实况图"], ["video", "视频"], ["embed", "平台视频"], ["music", "音乐"], ["link", "链接"], ["redpacket", "红包"]];
      this.root.innerHTML = '<label class="admin-lumina-field admin-lumina-type"><span>内容类型</span><select class="input" id="luminaType">' + types.map(function (item) {
        return '<option value="' + item[0] + '"' + (type === item[0] ? ' selected' : '') + '>' + item[1] + '</option>';
      }).join('') + '</select></label>' + fields.map(function (key) { return input(key, self.values[key] || ""); }).join('');
      document.getElementById("luminaType").addEventListener("change", function () { self.values.lumina_type = this.value; self.render(); });
    },
    collectFields: function () {
      if (!this.root) return [];
      var self = this;
      this.root.querySelectorAll("[data-lumina-key]").forEach(function (field) { self.values[field.getAttribute("data-lumina-key")] = field.value; });
      var type = this.values.lumina_type || "only";
      var active = (TYPE_KEYS[type] || []).concat(COMMON_KEYS);
      var hasValue = active.some(function (key) { return String(self.values[key] || "").trim() !== "" && !(key === "lumina_private" && self.values[key] === "n"); });
      if (!hasValue && type === "only") return [];
      return [{ key: "lumina_type", value: type }].concat(active.map(function (key) {
        return { key: key, value: String(self.values[key] || (key === "lumina_private" ? "n" : "")).trim() };
      }).filter(function (field) { return field.value !== ""; }));
    }
  });
})();
