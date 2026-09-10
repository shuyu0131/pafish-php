//监听屏幕上下滚动
window.onscroll = function () {
    //变量t是滚动条滚动时，距离顶部的距离
    var t = document.documentElement.scrollTop || document.body.scrollTop;
    //当滚动到距离顶部200px时，返回顶部的锚点显示
    if (t >= 230) {
        /* console.log("大于") */
        var headTop = document.getElementById("sh-main-head-top");
        if (headTop) {//判断顶部导航栏是否存在
            headTop.style.background = "var(--dbztlys)";
            headTop.style.backdropFilter = "saturate(180%) blur(20px)";
            headTop.style.webkitBackdropFilter = "saturate(180%) blur(20px)";
        }

        if (document.getElementById("top-left-1")) {//判断登录按钮是否存在
            document.getElementById("top-left-1").className = "iconfont icon-weibiaoti al-sxbh";
        }
        if (document.getElementById("top-right-1")) {//判断登录按钮是否存在
            document.getElementById("top-right-1").className = "iconfont icon-gengduo al-sxbh";
        }

        if (document.getElementById("setup-view-title")) {//判断消息按钮是否存在
            document.getElementById("setup-view-title").style = "color: var(--iconhs);";
        }
        
        var shMenu = document.getElementById("sh-menu");
        if (shMenu && shMenu.getAttribute("data-force") !== "1") {
            shMenu.style.display="flex";
        }
    } else {//恢复正常
        /* console.log("小于") */
        var headTop2 = document.getElementById("sh-main-head-top");
        if (headTop2) {//判断顶部导航栏是否存在
            headTop2.style.background = "var(--dbztlysh)";
            headTop2.style.backdropFilter = "";
            headTop2.style.webkitBackdropFilter = "";
        }

        if (document.getElementById("top-left-1")) {//判断登录按钮是否存在
            document.getElementById("top-left-1").className = "iconfont icon-weibiaoti al-sxb";
        }
        
        if (document.getElementById("top-right-1")) {//判断登录按钮是否存在
            document.getElementById("top-right-1").className = "iconfont icon-gengduo al-sxb";
        }

        if (document.getElementById("setup-view-title")) {//判断消息按钮是否存在
            document.getElementById("setup-view-title").style = "color: var(--iconbs);";
        }
        
        var shMenu2 = document.getElementById("sh-menu");
        if (shMenu2 && shMenu2.getAttribute("data-force") !== "1") {
            shMenu2.style.display="none";
        }
    }
}


/* 发送评论按钮事件 */
function fasongv() {
    var id = document.getElementById("sh-tieid").innerText;//获取点击的帖子id
    var tid = "sh-zanp-pl-" + id;
    
    if (document.getElementById("bletext").value == "") {
        warnpop("请输入评论内容");
        return;
    }
    var isLogin = window.LUMINA && window.LUMINA.isLogin;
    var allowGuest = window.LUMINA && window.LUMINA.allowGuest;
    
    if (document.getElementById("sh-plk-yk")) {
        var vis_name=document.getElementById("vis_name").value;//获取游客昵称
        var vis_email=document.getElementById("vis_email").value;//获取游客邮箱
        var vis_url=document.getElementById("vis_url").value;//获取游客网址
    } 
    
    
    if (!isLogin) {
        //没有登录账号
        if (!allowGuest || !document.getElementById("sh-plk-yk")) {
            warnpop("请先登录");
            return;
        }
        if (vis_name == "" && vis_email == "") {
            //warnpop("请先登录");
            ykkg();
            return;
        }else{
            if(vis_name == ""){
                warnpop("请输入昵称");
                return;
            }else if(vis_email == ""){
                warnpop("请输入邮箱");
                return;
            }
            if(!vis_email.match(/^\w+@\w+\.\w+$/i)){
                warnpop("邮箱格式不正确");
                return;
            }
            //判断网址是否正确
            if (vis_url != "") {
                /*if (vis_url.includes('http://') || vis_url.includes('https://')) {
                }else{
                    warnpop("网址必须含有http[s]://");
                    return;
                }*/
                var urlPattern = /^(https?:\/\/)?([\w.-]+)\.([a-z]{2,})(\/\S*)?$/i;
                if (!urlPattern.test(vis_url)) {
                    warnpop("网址格式不正确");
                    return;
                }
            }
        }
        
    }
        //登录了账号
        
        var tieid=document.getElementById("sh-tieid").innerText;//取文章id
        var tiehf=document.getElementById("sh-tiehf").innerText;//取评被评论者昵称
        var tieea=document.getElementById("sh-tieea").innerText;//取被评论者账号
        var pltext=document.getElementById("bletext").value;//获取评论内容
        
    
    

    var imgcodeInput = document.querySelector('input[name="imgcode"]');
    if (imgcodeInput && !imgcodeInput.value.trim()) {
        warnpop("请输入验证码");
        imgcodeInput.focus();
        return;
    }

    var form = document.createElement("form");
    form.method = "post";
    form.action = (window.LUMINA_BASE || "/") + "index.php?action=addcom";

    var addField = function(name, value){
        var input = document.createElement("input");
        input.type = "hidden";
        input.name = name;
        input.value = value || "";
        form.appendChild(input);
    };

    addField("gid", tieid);
    addField("pid", document.getElementById("sh-tiepid") ? document.getElementById("sh-tiepid").innerText : "0");
    addField("comment", pltext);

    if (!isLogin && allowGuest) {
        addField("comname", vis_name || "");
        addField("commail", vis_email || "");
        addField("comurl", vis_url || "");
    }
    if (imgcodeInput && imgcodeInput.value.trim()) {
        addField("imgcode", imgcodeInput.value.trim());
    }

    document.body.appendChild(form);
    form.submit();
        
        //登录了账号

}

document.addEventListener('DOMContentLoaded', function(){
    var captcha = document.getElementById('captcha');
    if (captcha) {
        captcha.addEventListener('click', function(){
            var base = this.getAttribute('src').split('?')[0];
            this.setAttribute('src', base + '?t=' + Date.now());
        });
    }
});







/*点赞按钮事件 */
function dinazanv() {
    if (window.luminaToggleLike) {
        return window.luminaToggleLike();
    }
    if (typeof warnpop === 'function') {
        warnpop('当前系统不支持点赞');
    }
    return false;
}








//删除文章事件
  




//删除评论
  function pldels(Obj){
      event.stopPropagation();//禁止冒泡
      
      if (confirm("确定要删除此评论吗?")) {
        // 用户点击了确认按钮
      } else {
        // 用户点击了取消按钮或关闭了弹窗
        return;
      }
      
      var eleecid = window.event.srcElement.id;//获取点击的id
      
      var elewidl = window.event.srcElement.lang;//获取当前点击的元素lang
      var liswd=elewidl.replace("yswid-","sh-zanp-pl-");
      var ulmli=liswd;

      
      
      loadpop("正在删除评论,请稍后...","ok");
      // 异步对象
      var xhr = new XMLHttpRequest();
      // 设置属性
      xhr.open('post', './api/delcomm.php');
      // 如果想要使用post提交数据,必须添加此行
      xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
      // 将数据通过send方法传递
      xhr.send('plid='+eleecid);
      // 发送并接受返回值
      xhr.onreadystatechange = function () {
          // 这步为判断服务器是否正确响应
          if (xhr.readyState == 4 && xhr.status == 200) {
              //alert(xhr.responseText);
              if (xhr.responseText == "") {
                  errorpop("未获取到数据");
                  return;
              }
              if (xhr.responseText == "删除评论成功!") {
                  //删除成功
                  Obj.parentNode.parentNode.removeChild(Obj.parentNode);//同步删除页面元素
                  
                  //判断父元素的父元素ul里面还有多少数据
                  lisew = document.getElementById(liswd).getElementsByTagName('li').length;//获取父元素的父元素里面还有没有li元素 没有则隐藏
                  if (lisew <=0) {
                      document.getElementById(ulmli).style.display="none";//当里面的数量为0或小于0时隐藏评论整个框架
                      var dzBlock = document.querySelector(".sh-dz-z");
                      if (dzBlock) {
                          dzBlock.style.display = "none";
                      }
                  }
                  //判断父元素的父元素ul里面还有多少数据
                  
                  successpop("已删除该评论");
                  //删除成功
              }else{
                  //删除失败
                  warnpop(xhr.responseText);
                  return;
              }
              
          }
      };
      
      
  }


//打开设置弹窗层
function viewsetk(){
    //显示最外层
    document.getElementById("sh-view-set").style.display="flex";
    //给弹窗菜单设置从下往上出现的动画
    document.getElementById("sh-view-set-wk-con").style.animation = "move_4 0.2s";
}
//关闭设置弹窗层
function viewsetg(e){
    var evt = e || window.event;
    if (evt && evt.stopPropagation) {
        evt.stopPropagation();//禁止冒泡
    }
    //让弹窗背景遮罩层淡出
    document.getElementById('sh-view-set-wk').style.transition = 'opacity 0.25s';
    document.getElementById('sh-view-set-wk').style.opacity = '0';
    //给给弹窗菜单设置从上往下的退出动画
    document.getElementById("sh-view-set-wk-con").style.animation = "move_4t 0.2s";

        let throttleTimer; // 声明用于节流的定时器变量
        function throttleFunction() {//定时器中要执行的代码
          if (document.getElementById("sh-view-set-wk-con")) { // 如果名为此id的div存在才执行
            //弹窗关闭后恢复所有的变动
            document.getElementById("sh-view-set").style.display = "none";
            document.getElementById('sh-view-set-wk').style.opacity = "";
            document.getElementById("sh-view-set-wk-con").removeAttribute("style");
            document.getElementById("sh-view-set-wk").removeAttribute("style");
          }
        }
        function throttle() {
          clearTimeout(throttleTimer); // 清除上一次的节流定时器
          throttleTimer = setTimeout(throttleFunction, 200); // 创建新的节流定时器
        }
        // 调用throttle函数来触发节流逻辑
        throttle();


}



function luminaTogglePrivate(el){
    if (!el) return;
    var state = el.getAttribute('data-hide-state') || 'n';
    var hideUrl = el.getAttribute('data-hide-url') || '';
    var pubUrl = el.getAttribute('data-pub-url') || '';
    var targetUrl = state === 'y' ? pubUrl : hideUrl;
    if (!targetUrl) return;
    if (state !== 'y' && !confirm('设为草稿后将从公开内容中隐藏，确定继续吗？')) {
        return;
    }
    if (typeof loadpop === 'function') {
        loadpop("正在处理...","ok");
    }
    fetch(targetUrl, { credentials: 'same-origin' })
        .then(function(){
            if (typeof successpop === 'function') {
                successpop(state === 'y' ? '已取消草稿' : '已设为草稿');
            }
            var newState = state === 'y' ? 'n' : 'y';
            el.setAttribute('data-hide-state', newState);
            var span = el.querySelector('span');
            if (span) {
                span.textContent = newState === 'y' ? '取消草稿' : '设为草稿';
            }
        })
        .catch(function(){
            if (typeof warnpop === 'function') {
                warnpop('操作失败');
            } else {
                alert('操作失败');
            }
        });
}

function luminaPostUserAction(el, action, payload){
    if (!el) return Promise.reject(new Error('missing element'));
    var url = el.getAttribute('data-action-url') || '';
    var blogid = el.getAttribute('data-blogid') || '';
    var token = el.getAttribute('data-token') || '';
    if (!url || !blogid || !token) {
        return Promise.reject(new Error('missing params'));
    }
    var fd = new FormData();
    fd.append('lumina_action', action);
    fd.append('blogid', blogid);
    fd.append('token', token);
    Object.keys(payload || {}).forEach(function(key){
        fd.append(key, payload[key]);
    });
    return fetch(url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(function(res){
        return res.json().catch(function(){
            throw new Error('响应解析失败');
        });
    }).then(function(resp){
        if (!resp || resp.code !== 0) {
            throw new Error(resp && resp.msg ? resp.msg : '操作失败');
        }
        return resp;
    });
}

function luminaSyncDetailBadge(type, active) {
    var target = document.querySelector('.sh-content-right-head-title > p');
    if (!target) return;
    var cls = type === 'top' ? 'lumina-log-badge-top' : 'lumina-log-badge-private';
    var existing = target.querySelector('.' + cls);
    if (!active) {
        if (existing) existing.remove();
        return;
    }
    if (existing) return;
    var badge = document.createElement('span');
    badge.className = 'lumina-log-badge ' + cls;
    badge.title = type === 'top' ? '置顶' : '仅自己可看';
    badge.setAttribute('aria-label', badge.title);
    badge.textContent = type === 'top' ? '置顶' : '私密';
    target.appendChild(badge);
}

function luminaToggleTop(el){
    if (!el) return;
    var state = el.getAttribute('data-top-state') || 'n';
    var next = state === 'y' ? 'n' : 'y';
    if (typeof loadpop === 'function') {
        loadpop("正在处理...","ok");
    }
    luminaPostUserAction(el, 'post_toggle_top', { top: next })
        .then(function(resp){
            var newState = resp.data && resp.data.top ? resp.data.top : next;
            el.setAttribute('data-top-state', newState);
            var span = el.querySelector('span');
            if (span) {
                span.textContent = newState === 'y' ? '取消置顶' : '设为置顶';
            }
            luminaSyncDetailBadge('top', newState === 'y');
            if (typeof successpop === 'function') {
                successpop(newState === 'y' ? '已置顶' : '已取消置顶');
            }
        })
        .catch(function(err){
            if (typeof warnpop === 'function') {
                warnpop(err.message || '操作失败');
            } else {
                alert(err.message || '操作失败');
            }
        });
}

function luminaToggleOnlyMe(el){
    if (!el) return;
    var state = el.getAttribute('data-private-state') || 'n';
    var next = state === 'y' ? 'n' : 'y';
    if (next === 'y' && !confirm('设为仅自己可看后，未登录和其他用户将不可见，确定继续吗？')) {
        return;
    }
    if (typeof loadpop === 'function') {
        loadpop("正在处理...","ok");
    }
    luminaPostUserAction(el, 'post_toggle_private', { private: next })
        .then(function(resp){
            var newState = resp.data && resp.data.private ? resp.data.private : next;
            el.setAttribute('data-private-state', newState);
            var span = el.querySelector('span');
            if (span) {
                span.textContent = newState === 'y' ? '取消仅自己可看' : '仅自己可看';
            }
            luminaSyncDetailBadge('private', newState === 'y');
            if (typeof successpop === 'function') {
                successpop(newState === 'y' ? '已设为仅自己可看' : '已恢复公开');
            }
        })
        .catch(function(err){
            if (typeof warnpop === 'function') {
                warnpop(err.message || '操作失败');
            } else {
                alert(err.message || '操作失败');
            }
        });
}

function luminaDeleteLog(el){
    if (!el) return;
    var url = el.getAttribute('data-del-url') || '';
    if (!url) return;
    if (!confirm('确定要删除此文章吗？')) {
        return;
    }
    if (typeof loadpop === 'function') {
        loadpop("正在删除...","ok");
    }
    fetch(url, { credentials: 'same-origin' })
        .then(function(){
            if (typeof successpop === 'function') {
                successpop('已删除');
            }
            var msg = document.getElementById('setup-view-title');
            if (msg) {
                msg.textContent = '已删除';
            }
            var content = document.querySelector('.sh-content');
            if (content) {
                content.style.opacity = '0.4';
                content.style.pointerEvents = 'none';
            }
        })
        .catch(function(){
            if (typeof warnpop === 'function') {
                warnpop('删除失败');
            } else {
                alert('删除失败');
            }
        });
}

if (document.getElementById('sh-view-set-wk-con')) {
  // 如果元素存在，则给它绑定事件
  document.getElementById('sh-view-set-wk-con').addEventListener('click', function(e) {
    if (e && e.stopPropagation) {
      e.stopPropagation();
    }
  });
}



//文章私密锁定与解除

  


//文章置顶与取消

// lumina override like handler
if (window.luminaToggleLike) { window.dinazanv = window.luminaToggleLike; }
if (window.luminaToggleLike) { window.dinazan = window.luminaToggleLike; }

