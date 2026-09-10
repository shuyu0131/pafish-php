function isScrollAtBottom() {
  const documentHeight = Math.max(
    document.body.scrollHeight,
    document.documentElement.scrollHeight,
    document.body.offsetHeight,
    document.documentElement.offsetHeight,
    document.body.clientHeight,
    document.documentElement.clientHeight
  );
  const windowHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;
  const scrollTop = window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop;
  return scrollTop + windowHeight >= documentHeight;
}

window.onscroll = function () {
    
    if (document.getElementById("sh-main-head-top")) {
        var mainHeadTop = document.getElementById('sh-main-head-top');
        
        var mainHeadTopLeft = document.querySelector('#sh-main-head-top .sh-main-head-top-left');
        var hasChildElements = mainHeadTopLeft && mainHeadTopLeft.children.length > 0;
        
        var mainHeadTopLeft2 = document.querySelector('#sh-main-head-top .sh-main-head-top-right');
        var hasChildElements2 = mainHeadTopLeft2 && mainHeadTopLeft2.children.length > 0;
        
        if (!hasChildElements && !hasChildElements2) {
          mainHeadTop.parentNode.removeChild(mainHeadTop);
        }
    }
    
    var t = document.documentElement.scrollTop || document.body.scrollTop;
    if (t >= 230) {
        var headTop = document.getElementById("sh-main-head-top");
        if (headTop) {//判断顶部导航栏是否存在
            headTop.style.background = "var(--dbztlys)";
            headTop.style.backdropFilter = "saturate(180%) blur(20px)";
            headTop.style.webkitBackdropFilter = "saturate(180%) blur(20px)";
        }
        
        if (document.getElementById("top-left-1")) {//判断登录按钮是否存在
            document.getElementById("top-left-1").className = "iconfont icon-weibiaoti al-sxbh";
        }
        if (document.getElementById("top-left-xx")) {
            document.getElementById("top-left-xx").className = "iconfont icon-weibiaoti ri-sxh";
        }


        var topRight1 = document.getElementById("top-right-1");
        if (topRight1 && topRight1.getAttribute('data-top-icon-role') === 'notice') {//判断消息按钮是否存在
            topRight1.className = "iconfont icon-lingdang al-sxb";
        }
        
        if (document.getElementById("top-right-2")) {//判断消息按钮是否存在
            var topRight2 = document.getElementById("top-right-2");
            if (!topRight2.getAttribute('data-icon-lock')) {
                topRight2.className = "iconfont icon-tongxunlu ri-sxh";
            }
        }
        
        if (document.getElementById("top-right-3")) {//判断发布按钮是否存在
            document.getElementById("top-right-3").className = "iconfont icon-xiangji1 ri-sxh";
        }
        
        
        if (document.getElementById("sh-main-top-mu")) {//判断消息按钮是否存在
        var tul=document.querySelector("#sh-main-top-mu").getAttribute("data-bfzt");//获取当前的图标
        if (tul == "bb") {
            document.getElementById("sh-main-top-mu").className = "iconfont icon-bofang-tongyong-copy ri-z-sxh";
            document.querySelector("#sh-main-top-mu").setAttribute("data-bfzt","bbh");
        }else if(tul == "bbz"){
            document.getElementById("sh-main-top-mu").className = "iconfont icon-iconstop ri-z-sxh";
            document.querySelector("#sh-main-top-mu").setAttribute("data-bfzt","zbbh");
        }
        }
        
        if (document.getElementById("sh-main-top-mu-bgmq")) {//判断消息按钮是否存在
            document.getElementById("sh-main-top-mu-bgmq").className = "iconfont icon-yinle_2 ri-z-sxh";
        }
        
        var shMenu = document.getElementById("sh-menu");
        if (shMenu && shMenu.getAttribute("data-force") !== "1") {
            shMenu.style.display="flex";
        }
        
    } else {          //恢复正常
        var headTop2 = document.getElementById("sh-main-head-top");
        if (headTop2) {//判断顶部导航栏是否存在
            headTop2.style.background = "var(--dbztlysh)";
            headTop2.style.backdropFilter = "";
            headTop2.style.webkitBackdropFilter = "";
        }
        
        if (document.getElementById("top-left-1")) {//判断登录按钮是否存在
            document.getElementById("top-left-1").className = "iconfont icon-weibiaoti al-sxb";
        }
        if (document.getElementById("top-left-xx")) {
            document.getElementById("top-left-xx").className = "iconfont icon-weibiaoti ri-sx";
        }

        
        var topRight1b = document.getElementById("top-right-1");
        if (topRight1b && topRight1b.getAttribute('data-top-icon-role') === 'notice') {//判断消息按钮是否存在
            topRight1b.className = "iconfont icon-lingdang al-sxb";
        }
        
        if (document.getElementById("top-right-2")) {//判断消息按钮是否存在
            var topRight2b = document.getElementById("top-right-2");
            if (!topRight2b.getAttribute('data-icon-lock')) {
                topRight2b.className = "iconfont icon-tongxunlu-copy ri-sx";
            }
        }
        
        if (document.getElementById("top-right-3")) {//判断消息按钮是否存在
            document.getElementById("top-right-3").className = "iconfont icon-xiangji2 ri-sx";
        }
        
        
        if (document.getElementById("sh-main-top-mu")) {//判断消息按钮是否存在
        var tul=document.querySelector("#sh-main-top-mu").getAttribute("data-bfzt");//获取当前的图标
        if (tul == "bbh") {
            document.getElementById("sh-main-top-mu").className = "iconfont icon-jixu ri-z-sx";
            document.querySelector("#sh-main-top-mu").setAttribute("data-bfzt","bb");
        }else if(tul == "zbbh"){
            document.getElementById("sh-main-top-mu").className = "iconfont icon-iconstop ri-z-sx";
            document.querySelector("#sh-main-top-mu").setAttribute("data-bfzt","bbz");
        }
        }
        
        if (document.getElementById("sh-main-top-mu-bgmq")) {//判断消息按钮是否存在
            document.getElementById("sh-main-top-mu-bgmq").className = "iconfont icon-yinle_2 ri-z-sx";
        }
        
        var shMenu2 = document.getElementById("sh-menu");
        if (shMenu2 && shMenu2.getAttribute("data-force") !== "1") {
            shMenu2.style.display="none";
        }
    }
    
    
    var footerLoadMore = document.getElementById("footer-text-zt");
    if (footerLoadMore && isScrollAtBottom()) {
      hqgd();
    }
}



　　　　

/* 评论合集开 --评论与点赞按钮打开或关闭*/
function plk() {
    var ele = '';
    if (window.event) {
        var target = window.event.target || window.event.srcElement;
        var node = target;
        while (node && !node.id) {
            node = node.parentElement;
        }
        if (node && node.id) {
            ele = node.id;
        }
    }
    if (!ele) { return; }
    /* console.log(scid); */
    var ids = "pl-" + ele;
    if (document.getElementById(ids).style.display != "flex") {
        //先隐藏上次打开评论菜单↓
        var arrs = document.getElementsByName("pl");
        for (var i = 0; i < arrs.length; i++) {
            /* alert(arrs[i].id); */
            document.getElementById(arrs[i].id).style = "display: none";
        }
        //先隐藏上次打开的评论菜单↑
        document.getElementById(ids).style = "display: flex";
    } else {
        document.getElementById(ids).style = "display: none";
    }
    //设置参数id
    document.getElementById("sh-tieid").innerText = ele;
}



//表情列表开关
function bqkg() {
    event.stopPropagation();//禁止冒泡
    
    var textarea = document.getElementById("bletext");
    textarea.focus(); // 将焦点设置到textarea
    
    if (document.getElementById('biaoqing').style.display != "grid") {
        document.getElementById('biaoqing').style = "display: grid";
        document.getElementById('sh-pinglun-fs-right-bqimg').className = "iconfont icon-biaoqing ri-sxbqxzls"//修改表情开关为绿色
    } else {
        document.getElementById('biaoqing').style = "display: none";
        document.getElementById('sh-pinglun-fs-right-bqimg').className = "iconfont icon-biaoqing ri-sxbqxz"//修复表情开关为灰色
    }
}




//游客输入框开关
function ykkg() {
    event.stopPropagation();//禁止冒泡

    var textarea = document.getElementById("bletext");
    textarea.focus(); // 将焦点设置到textarea

    if (document.getElementById('sh-plk-yk').style.display != "flex") {
        document.getElementById('sh-plk-yk').style = "display: flex";
        document.getElementById('sh-pinglun-fs-right-ykkgb').className = "iconfont icon-yonghu1 ri-sxbqxzls"//修改表情开关为绿色
    } else {
        document.getElementById('sh-plk-yk').style = "display: none";
        document.getElementById('sh-pinglun-fs-right-ykkgb').className = "iconfont icon-yonghu1 ri-sxbqxz"//修复表情开关为灰色
    }
}




//评论框插入与删除
function plkkg() {
    var ele = '';
    if (window.event) {
        var target = window.event.target || window.event.srcElement;
        var node = target;
        while (node && !node.id) {
            node = node.parentElement;
        }
        if (node && node.id) {
            ele = node.id;
        }
    }
    if (!ele) { return; }
    var tieId = document.getElementById("sh-tieid");
    if (tieId) { tieId.innerText = ele; }

    if (window.event && window.event.stopPropagation) { window.event.stopPropagation(); }

    var shList = document.getElementById("sh-zanp-pl-"+ele);
    if (!shList) { return; }
    var wrap = document.getElementById("zanss-"+ele);
    if (wrap && wrap.style.display === "none") {
        wrap.style.display = "block";
    }
    if (shList.style.display == "none" || shList.style.display === "") {
        shList.style.display = "block";
    }
    //详情页
    var dz = document.getElementById("sh-dz-z-"+ele);
    if (dz && dz.style.display == "none") {
        dz.style.display = "flex";
    }
    
    var shZanpPlElement = shList;
    var firstChildElement = shZanpPlElement.firstElementChild;
    if (firstChildElement && firstChildElement.id === 'pinglunkuang') {
        //恢复原位
        plkgb();//清除上次插入操作
        //恢复原位
    } else {
        //在执行完上面代码后都需要把所有的自定义属性都改为0，防止有的属性没有复位到0导致bug
        var elements = document.querySelectorAll('[data-comkzt]');
        for (var i = 0; i < elements.length; i++) {
            elements[i].setAttribute('data-comkzt', '0');
        }
        
        //移动到第一个位置
        var pinglunkuangElement = document.getElementById('pinglunkuang');
        var shZanpPlElement = document.getElementById('sh-zanp-pl-'+ele);
    if (!shZanpPlElement) {
        return;
    }
        shZanpPlElement.insertBefore(pinglunkuangElement, shZanpPlElement.firstChild);
        
        //隐藏所有没有评论但是显示了的评论列表元素
        var elements = document.getElementsByClassName("sh-zanp-pl");
        for (var i = 0; i < elements.length; i++) {
          var element = elements[i];
          if (element.children.length === 0) {
            element.style.display = "none";
          }
        }
        
        document.getElementById("sh-tiehf").innerText = "false";
        document.getElementById("sh-tieea").innerText = "false";
        document.getElementById("bletext").placeholder = "评论";
        var textarea = document.getElementById("bletext");
        textarea.focus(); // 将焦点设置到textarea
    }

    //隐藏菜单
    var ids = "pl-" + ele;
    if (document.getElementById(ids).style.display != "none") {
        //先隐藏上次打开评论菜单↓
        var arrs = document.getElementsByName("pl");
        for (var i = 0; i < arrs.length; i++) {
            /* alert(arrs[i].id); */
            document.getElementById(arrs[i].id).style = "display: none";
        }
        //先隐藏上次打开的评论菜单↑
    }

}




function plkgb() {
    //设置出场动画

    //恢复输入框到原位
    var shPinglunElement = document.getElementById('pinglunkuang');
    var pinglunkfkElement = document.getElementById('pinglunkfk');
    pinglunkfkElement.appendChild(shPinglunElement);
    
    //在设置1前先把所有的自定义属性都改为0，防止有的属性没有复位到0导致bug
    var elements = document.querySelectorAll('[data-comkzt]');
    for (var i = 0; i < elements.length; i++) {
        elements[i].setAttribute('data-comkzt', '0');
    }
    
    //隐藏所有没有评论但是显示了的评论列表元素
    var elements = document.getElementsByClassName("sh-zanp-pl");
    for (var i = 0; i < elements.length; i++) {
        var element = elements[i];
        if (element.children.length === 0) {
            element.style.display = "none";
            //详情页
            var element = document.querySelector('.sh-dz-z');
            if (element) {
              element.style.display = 'none';
            }
            //详情页
        }
    }
    
    //清空输入框内容
    document.getElementById("bletext").value = "";
    document.getElementById("sh-tieid").innerText="-";
    document.getElementById("sh-tiehf").innerText="-";
    document.getElementById("sh-tieea").innerText="-";
    if (document.getElementById("sh-tiepid")) {
        document.getElementById("sh-tiepid").innerText="0";
    }
}







/* 表情按钮事件 */
var input = document.getElementById("bletext");
var rangeIndex=null//光标地位
//监听失焦
input.onblur = function(){
  rangeIndex = this.selectionStart;//获取失焦时光标的地位
}
//插入函数
function biaoqzj(){
    var ele = window.event.srcElement.alt;//获取点击的表情alt
  if(rangeIndex){
    let oldVaue = input.value;
    input.value = oldVaue.slice(0,rangeIndex)+ele+oldVaue.slice(rangeIndex);
    rangeIndex = rangeIndex+ele.toString().length;
  }else{
    let oldVaue = input.value;
    input.value = oldVaue.slice(0,rangeIndex)+ele+oldVaue.slice(rangeIndex);
    rangeIndex = rangeIndex+ele.toString().length;
  }
  input.focus();
  input.setSelectionRange(rangeIndex,rangeIndex)//从新定位光标
}
    




//获取cookie函数
function getCookie(cookieName) {
    var strCookie = document.cookie;
    var arrCookie = strCookie.split("; ");
    for(var i = 0; i < arrCookie.length; i++){
        var arr = arrCookie[i].split("=");
        if(cookieName == arr[0]){
            return arr[1];
        }
    }
    return "";
}







/*点赞按钮事件 */
function dinazan() {
    if (window.luminaToggleLike) {
        return window.luminaToggleLike();
    }
    if (typeof warnpop === 'function') {
        warnpop('当前系统不支持点赞');
    }
    return false;
}





function plhuifu() {
    var ev = window.event || event;
    var node = ev && ev.target ? ev.target : null;
    while (node && node.tagName !== 'LI') {
        node = node.parentElement;
    }
    if (!node) {
        return;
    }
    //获取点击的被回复者名字
    var elee = node.getAttribute('lang');
    //获取点击的帖子id
    var eles = node.getAttribute('id');
    //获取点击的邮箱
    var elea = node.getAttribute('value');
    var dqzdsx = node.getAttribute('data-comkzt');//取自定义属性 0为移动当前，1为恢复原位
    var cid = node.getAttribute('data-cid');
    
    
    
    //移动div
    //document.addEventListener('click', function(event) {
      var target = node;
      var parentList = node.parentElement;
      if (parentList && parentList.id === "sh-zanp-pl-"+eles) {
          if(dqzdsx == "1"){
              //恢复原位
              plkgb();//清除上次插入操作
              //恢复原位
          }else if(dqzdsx == "0"){
              //在设置1前先把所有的自定义属性都改为0，防止有的属性没有复位到0导致bug
              var elements = document.querySelectorAll('[data-comkzt]');
              for (var i = 0; i < elements.length; i++) {
                elements[i].setAttribute('data-comkzt', '0');
              }
              
              //移动到当前点击的元素后面
              var divToMove = document.getElementById('pinglunkuang');
              target.insertAdjacentElement('afterend', divToMove);

              // 修改自定义属性的值为1
              target.setAttribute('data-comkzt', '1');//禁止再次移动到当前元素
              //移动到当前点击的元素后面
              
              //获得焦点
              var textarea = document.getElementById("bletext");
              textarea.focus(); // 将焦点设置到textarea
              
              //隐藏所有没有评论但是显示了的评论列表元素
              var elements = document.getElementsByClassName("sh-zanp-pl");
              for (var i = 0; i < elements.length; i++) {
                var element = elements[i];
                if (element.children.length === 0) {
                  element.style.display = "none";
                }
              }
              
              //设置参数id
              document.getElementById("sh-tieid").innerText = eles;
              document.getElementById("sh-tiehf").innerText = elee;
              document.getElementById("sh-tieea").innerText = elea;
              document.getElementById("bletext").placeholder = "回复";
              if (cid && document.getElementById("sh-tiepid")) {
                  document.getElementById("sh-tiepid").innerText = cid;
              }
          }

      }
    //});
}






//回复者名字url时间
function hfljurl(){
    //点击评论者的名字时跳转到它的网站,并且禁止冒泡，防止触发父元素事件
    event.stopPropagation();
}







/* 开启登录 */
function kqlogin() {
    var user_id = getCookie("username");//取登录的账号
    var user_passid = getCookie("passid");//取登录的passid唯一id
    
    if (user_id == "" || user_passid == "") {
        //没有登录账号
    }else{
        //登录了账号
        return;
    }
    document.getElementById("sh-login").style.display = "flex";
    /*var dqzy=document.getElementById("sh-login").className;
    var xgbzt=dqzy.replace(" move_4t","");
    document.getElementById("sh-login").className =xgbzt;
    document.getElementById("sh-login").className +=" move_4";*/
}

/* 关闭登录与注册弹窗 */
function gblogin() {
    /*var dqzy=document.getElementById("sh-login").className;
    var xgbzt=dqzy.replace(" move_4"," move_4t");
    document.getElementById("sh-login").className =xgbzt;
    window.setTimeout(function () {
            if (document.getElementById("sh-login")) {*///如果名为此id的div存在才执行
                document.getElementById("sh-login").style = "display: none";
            /*}
        }, 250);*/
}

/* 友链弹窗 */
function kqlink() {
    var el = document.getElementById('sh-link');
    if (!el) return;
    el.style.display = 'flex';
}
function gblink() {
    var el = document.getElementById('sh-link');
    if (!el) return;
    el.style.display = 'none';
}





/* 消息通知弹窗 */
/* 开启 */
function kqnews() {
    document.getElementById("sh-news").style.display = "flex";
    //js_open()

}
/* 关闭 */
function gbnews() {
    document.getElementById("sh-news").style.display = "none";
}






/* 发送评论按钮事件 */
function fasong() {
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
            if(document.getElementById("sh-login")){
                document.getElementById("sh-login").style.display="flex";
            }else{
                warnpop("请先登录");
            }
            return;
        }
        if (vis_name == "" && vis_email == "") {
            ykkg();
            return;
            /*document.getElementById("sh-login").style.display="flex";
            var dqzy=document.getElementById("sh-login").className;
            var xgbzt=dqzy.replace(" move_4t","");
            document.getElementById("sh-login").className =xgbzt;
            document.getElementById("sh-login").className +=" move_4";
            return;*/
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













/* 音乐播放控制 */
function audbf(){
    var ele = window.event.srcElement.lang;//获取点击的id
    var name="musicurl-"+ele;//合成待取音乐播放地址
    var nam=document.getElementById(name).lang;//取音乐播放地址
    var naf=document.getElementById(name).className;//取音乐封面
    var bfkztu="sh-aud-left-plak-"+ele;//获得文章播放控制按钮
    var bfz=document.getElementById("musicplay").lang;//获得独立播放器播放状态

    var audio = document.getElementById("musicplay");//取到独立音乐播放器

    if (bfz == 0) {//等于0说明未播放 需要把音乐信息传给独立播放器
        if (ele != document.getElementById("musicplay").className) {
            document.getElementById("musicplay").src=nam;//将音乐播放地址传给独立播放器
            document.getElementById("musicplay").lang="1";//将播放器状态设为播放中
            document.getElementById("yszt").src=naf;//将音乐封面传给播放器
            document.getElementById("ming").src=naf;//将音乐封面传给播放器背景
            document.getElementById("musicplay").className=ele;//将本次的文章音乐id传给播放器
        }
        
        //设置封面
        var content= document.getElementById('yszt');
        content.dataset.src=naf;
        //设置背景
        var content= document.getElementById('ming');
        content.dataset.src=naf;
        
        
        var bfz=document.getElementById("musicplay").lang;//传完后再次获得播放状态
        
        if (document.getElementById("sh-main-top-musicplay-b")) {
            document.getElementById("sh-main-top-musicplay-b").pause();//暂停首页顶部音乐
        }
        audio.play();//开始播放音乐
            document.getElementById(bfkztu).lang="1";//更新播放状态为播放中
            document.getElementById("musicplay").lang ="1"//将播放器状态设为播放中
            document.getElementById(bfkztu).className="iconfont icon-iconstop";//开始播放后设置播放按钮图片为暂停的图片
            document.getElementById("sh-musiccz-zb").className="iconfont icon-iconstop ri-z-sx"//开始播放设置独立音乐播放器为暂停图标
            if(document.getElementById("musicbc").lang == 1){
                document.getElementById("musicbc").style.right="10px";//显示独立音乐播放器
            }else{
                document.getElementById("musicbc").style.right="-80px";//显示独立音乐播放器
            }
            
            timer=setInterval(function(){
               if(audio.paused){
                   document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
                   document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
                   document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
                   document.getElementById("musicplay").lang ="0"//将播放器状态设为未播放
                   clearInterval(timer);
                   return;
               }
            },500);
    }else if (bfz == 1) {//等于1说明音乐播放中 需要暂停
        audio.pause();//暂停播放音乐
        clearInterval(timer);//关闭定时器
        document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
        document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
        document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
        document.getElementById("musicplay").lang ="0"//将播放器状态设为未播放
        document.getElementById("sh-aud-left-plak-"+document.getElementById("musicplay").className).className="iconfont icon-jixu"
    }
    
    
    
    
if (bfz == 0 || bfz == 1) {
    if (ele != document.getElementById("musicplay").className) {

        document.getElementById("musicplay").src=nam;//将音乐播放地址传给独立播放器
        document.getElementById("musicplay").lang="1";//将播放器状态设为播放中
        document.getElementById("yszt").src=naf;//将音乐封面传给播放器
        document.getElementById("ming").src=naf;//将音乐封面传给播放器背景
        document.getElementById("musicplay").className=ele;//将本次的文章音乐id传给播放器
        

            audio.play();//开始播放音乐
            document.getElementById(bfkztu).lang="1";//更新播放状态为播放中
            document.getElementById("musicplay").lang ="1"//将播放器状态设为播放中
            document.getElementById(bfkztu).className="iconfont icon-iconstop";//开始播放后设置播放按钮图片为暂停的图片
            document.getElementById("sh-musiccz-zb").className="iconfont icon-iconstop ri-z-sx"//开始播放设置独立音乐播放器为暂停图标
            if(document.getElementById("musicbc").lang == 1){
                document.getElementById("musicbc").style.right="10px";//显示独立音乐播放器
            }else{
                document.getElementById("musicbc").style.right="-80px";//显示独立音乐播放器
            }
        
            timer=setInterval(function(){
               if(audio.paused){
                   document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
                   document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
                   document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
                   document.getElementById("musicplay").lang ="0"//将播放器状态设为未播放
                   clearInterval(timer);
                   return;
               }
            },500);
    }
}


}



//独立音乐播放器播放按钮事件
function bfpy(){//播放与暂停
    var ele=document.getElementById("musicplay").className;
    var bfkztu="sh-aud-left-plak-"+ele;//获得文章播放控制按钮
    var bfz=document.getElementById("musicplay").lang;//获得独立播放器播放状态
    var audio = document.getElementById("musicplay");//取到独立音乐播放器
    
    
    if (bfz == 0) {//0为未播放
            if (document.getElementById("sh-main-top-musicplay-b")) {
                document.getElementById("sh-main-top-musicplay-b").pause();//暂停首页顶部音乐
            }
            audio.play();//开始播放音乐
            document.getElementById(bfkztu).lang="1";//更新播放状态为播放中
            document.getElementById("musicplay").lang ="1"
            document.getElementById(bfkztu).className="iconfont icon-iconstop";//开始播放后设置播放按钮图片为暂停的图片
            document.getElementById("sh-musiccz-zb").className="iconfont icon-iconstop ri-z-sx"//开始播放设置独立音乐播放器为暂停图标
            if(document.getElementById("musicbc").lang == 1){
                document.getElementById("musicbc").style.right="10px";//显示独立音乐播放器
            }else{
                document.getElementById("musicbc").style.right="-80px";//显示独立音乐播放器
            }
            
        
            timer=setInterval(function(){
               if(audio.paused){
                   document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
                   document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
                   document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
                   document.getElementById("musicplay").lang ="0"//将播放器状态设为未播放
                   clearInterval(timer);
                   return;
               }
            },500);
        }else if (bfz == 1) {//等于2说明音乐播放中 需要暂停
        audio.pause();//暂停播放音乐
        //clearInterval(timer);//关闭定时器
        document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
        document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
        document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
        document.getElementById("musicplay").lang ="1"//将播放器状态设为播放中
        document.getElementById("sh-aud-left-plak-"+document.getElementById("musicplay").className).className="iconfont icon-jixu"
    }
}



//清除歌曲并隐藏播放器
function bfpg(){
    var ele=document.getElementById("musicplay").className;
    var bfkztu="sh-aud-left-plak-"+ele;//获得文章播放控制按钮
    var bfz=document.getElementById("musicplay").lang;//获得独立播放器播放状态

    document.getElementById("musicplay").scr=""
    var audio = document.getElementById("musicplay");//取到独立音乐播放器
    

        audio.pause();//暂停播放音乐
        clearInterval(timer);//关闭定时器
        document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
        document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
        document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
        document.getElementById("musicplay").lang ="0"//将播放器状态设为未播放

    document.getElementById("sh-aud-left-plak-"+document.getElementById("musicplay").className).className="iconfont icon-jixu"
    document.getElementById("musicplay").className =""
    if(document.getElementById("musicbc").lang == 1){
        document.getElementById("musicbc").style.right="-200px";
    }else{
        document.getElementById("musicbc").style.right="-800px";
    }
}


//独立播放器封面点击展开与收起
function mbpy(){
    if(document.getElementById("musicbc").lang == 1){
        return;
    }
    var elez = document.getElementById("yszt").lang;
    if(elez == 0){
        //展开
        const myDiv = document.getElementById('musicbc');
        myDiv.style.transform = 'translateX(-80px)';
        document.getElementById("yszt").lang="1";
    }else{
        //收起
        const myDiv = document.getElementById('musicbc');
        myDiv.style.transform = 'translateX(0px)';
        document.getElementById("yszt").lang="0";
    }
}







//注册账号按钮事件
function regzc(){
    //执行账号注册
        var zh=document.getElementById("login-zh").value;//取账号
        var em=document.getElementById("login-email").value;//取邮箱
        var mm=document.getElementById("login-pass").value;//取密码
        if (document.getElementById("login-yzm")) {
            var yzm=document.getElementById("login-yzm").value;//取验证码
        }
        //判断所有参数是否为空和邮箱格式是否正确
        if (zh == "") {
            //alert("账号未输入!");
            warnpop("账号未输入");
            return;
        }else if(em == ""){
            //alert("邮箱未输入!");
            warnpop("邮箱未输入");
            return;
        }else if(mm == ""){
            //alert("密码未输入!");
            warnpop("密码未输入");
            return;
        }else{
            var regx = /^\w+([-+.']\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/; 
            if (regx.test(em) != true) {
                //alert("邮箱格式错误!");
                warnpop("邮箱格式错误");
                return;
            }
        }
        if (document.getElementById("login-yzm")) {
            if (yzm == "") {
                warnpop("验证码未输入");
                return;
            }
        }
        
        //判断账号密码长度是否符合
        if (zh.length < 5) {//账号要求 不小于5 不大于32 位
            warnpop("账号不可低于5位数");
            return;
        }else if(zh.length > 32){
            warnpop("账号不可大于32位数");
            return;
        }else if(mm.length < 3){
            warnpop("密码不可低于3位数");
            return;
        }else if(mm.length > 16){
            warnpop("密码不可大于16位数");
            return;
        }
        
        //显示提示信息
        loadpop("正在注册账号，请稍后...","ok")
        
        //提交注册
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/reg.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('zh='+zh+'&em='+em+'&mm='+mm+"&allkey="+myallkeyVar+"&yzm="+yzm);
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            if (xhr.responseText == "") {
                //alert("未获取到数据!");
                errorpop("未获取到数据");
                return;
            }
            if (xhr.responseText == "账号注册成功!") {
                //注册成功
                //alert(xhr.responseText);
                successpop(xhr.responseText);
                //document.getElementById("login-zh").value="";//清空账号
                document.getElementById("login-email").value="";//清空邮箱
                //document.getElementById("sh-login-main-con-anu").style.display="none";//隐藏邮箱输入框
                //document.getElementById("sh-left-dl").style.display="block";//显示登录按钮
                //document.getElementById("sh-left-zc").style.display="none";//隐藏注册按钮
                //document.getElementById("sh-zck-an").innerText="注册账号";//底部切换
                //document.getElementById("login-pass").value="";//清空密码
                zcanxy();
                
            }else{
                //注册失败
                errorpop(xhr.responseText);
            }
        }
    };
        //执行账号注册
    
}


//发送注册验证码
if (document.getElementById('yzm')) {
  // 如果元素存在，则给它绑定事件
  document.getElementById('yzm').addEventListener('click', function() {
      var zh=document.getElementById("login-zh").value;//取账号
      var em=document.getElementById("login-email").value;//取邮箱
      var mm=document.getElementById("login-pass").value;//取密码
      
      if (document.getElementById("yzm").innerText != "发送") {
          return;
      }
      if(em == ""){
            warnpop("邮箱未输入");
            return;
        }else{
            var regx = /^\w+([-+.']\w+)*@\w+([-.]\w+)*\.\w+([-.]\w+)*$/; 
            if (regx.test(em) != true) {
                warnpop("邮箱格式错误");
                return;
            }
        }
        
      //显示提示信息
        loadpop("正在发送验证码，请稍后...","ok")
        
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/reg.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('zh='+zh+'&em='+em+'&mm='+mm+"&allkey="+myallkeyVar+"&fsyzm=1");
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            if (xhr.responseText == "") {
                //alert("未获取到数据!");
                errorpop("未获取到数据");
                return;
            }
            if (xhr.responseText == "发送成功") {
                //成功
                successpop(xhr.responseText);
                let countDown = 60; // 倒计时秒数  
                let yzmElement = document.getElementById('yzm'); // 获取id为yzm的元素  
                // 设置倒计时函数  
                let countDownFunction = setInterval(function() {  
                  yzmElement.textContent = '发送(' + countDown + '秒)'; // 更新元素内容  
                  countDown--; // 减少倒计时秒数  
                  if (countDown <= 0) { // 如果倒计时结束  
                    yzmElement.textContent = '发送'; // 设置元素内容为"发送"  
                    clearInterval(countDownFunction); // 停止倒计时函数  
                  }  
                }, 1000); // 每秒更新一次
            }else{
                //失败
                errorpop(xhr.responseText);
            }
        }
    };
    
  });
}




//禁止注册账号和找回密码按钮响应回车事件


//注册按钮显示与隐藏
function zcanxy(){
    var zcan=document.getElementById("sh-login-main-con-anu").style.display;
    if (zcan == "none") {
        //document.getElementById("sh-left-zc").style.display="block";//显示注册按钮
        //document.getElementById("sh-left-dl").style.display="none";//隐藏登录按钮
        document.getElementById("sh-login-main-con-anu").style.display="flex";//显示邮箱
        if (document.getElementById("login-yzm")) {
            document.getElementById("sh-login-main-con-yzmwk").style.display="flex";//显示验证码
        }
        document.getElementById("sh-zck-an").innerText="登录账号";//设置底部开关注册为登录
        document.getElementById("zhdzsx").innerText="账号注册";//设置标题
        //document.getElementById("sh-left-zc").type="submit";
        //document.getElementById("sh-left-dl").type="button";
        document.getElementById("sh-left-dlzc").className="sh-left"
        document.getElementById("sh-left-dlzc").value="注册";
        document.getElementById('sh-left-dlzc').setAttribute('onclick', 'regzc()');

    }else{
        //document.getElementById("sh-left-zc").style.display="none";//隐藏注册按钮
        //document.getElementById("sh-left-dl").style.display="block";//显示登录按钮
        document.getElementById("sh-login-main-con-anu").style.display="none";//隐藏邮箱
        if (document.getElementById("login-yzm")) {
            document.getElementById("sh-login-main-con-yzmwk").style.display="none";//隐藏验证码
        }
        document.getElementById("sh-zck-an").innerText="注册账号";//设置底部开关注册为注册
        document.getElementById("zhdzsx").innerText="账号登录";//设置标题
        //document.getElementById("sh-left-zc").type="button";
        //document.getElementById("sh-left-dl").type="submit";
        //document.getElementById('sh-left-dl').setAttribute('onclick', 'a()');
        document.getElementById("sh-left-dlzc").className="sh-right"
        document.getElementById("sh-left-dlzc").value="登录";
        document.getElementById('sh-left-dlzc').setAttribute('onclick', 'logy()');
    }
    return false;
}





//找回密码事件






//登录按钮事件
function logy(){
    //执行登录
        var zh=document.getElementById("login-zh").value;//取账号
        var mm=document.getElementById("login-pass").value;//取密码
        if (zh == "") {
            warnpop("请输入账号");
            return;
        }else if(mm == ""){
            warnpop("请输入密码");
            return;
        }
        
        //判断账号密码长度是否符合
        if (zh.length < 5) {//账号要求 不小于5 不大于32 位
            warnpop("账号不可低于5位数");
            return;
        }else if(zh.length > 32){
            warnpop("账号不可大于32位数");
            return;
        }else if(mm.length < 3){
            warnpop("密码不可低于3位数");
            return;
        }else if(mm.length > 16){
            warnpop("密码不可大于16位数");
            return;
        }
        
        //显示提示信息,带遮罩
        loadpop("登录中，请稍后...","ok");
        
        
        //提交登录
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/login.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('zh='+zh+'&mm='+mm);
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            if (xhr.responseText == "") {
                errorpop("未获取到数据");
                return;
            }
            if (xhr.responseText == "登录成功!") {
                //登录成功
                successpop("登录成功，即将跳转","ok");
                window.location.href="./index.php";
                //登录成功
            }
            else{
                //登录失败
                errorpop(xhr.responseText);
            }
            
        }
    };
        
        //执行登录
}






//消息操作菜单开关
function js_menu() {
    if (document.getElementById("xxtzsul").innerText == "0") {
        return;
    }
    var adElement = document.getElementById('js_menu');
    var isAdVisible = adElement.style.display === 'none';
    
    if (isAdVisible) {
      adElement.style.display = 'flex';
    } else {
      adElement.style.display = 'none';
    }
}
      
//消息删除选中
function xxsczt(){
    event.stopPropagation();
    if (document.getElementById("xxtzsul").innerText == "0") {
        return;
    }
    var xxsczt=document.getElementById("xxsczt").lang;//获取消息选中状态 0为已读 -1为删除
    if (xxsczt == 0) {
        //为0 则设置成-1 并且改变消息列表颜色 进入删除状态
        document.getElementById("xxsczt").lang="-1";
        //document.getElementById("xxsczt").className="iconfont icon-shanchu ri-sxhsh";
        document.getElementById("js_menu").style.display="none";
        //document.getElementById("sh-news-con").style="background: var(--fgxys);";
        //document.getElementById("xxscztqbk").style.display="flex";
        
        document.querySelectorAll('.sh-xxliebfm .delmes').forEach(element => {
            element.style.display = 'flex';
        });
        
        //为0 则设置成-1 并且改变消息列表颜色 进入删除状态
    }else{
        //设置成0 并且改变消息列表颜色 进入正常状态
        document.getElementById("xxsczt").lang="0";
        //document.getElementById("xxsczt").className="iconfont icon-shanchu ri-sxhs";
        document.getElementById("js_menu").style.display="none";
        //document.getElementById("sh-news-con").style="background: var(--cobg)";
        //document.getElementById("xxscztqbk").style.display="none";
        
        document.querySelectorAll('.sh-xxliebfm .delmes').forEach(element => {
            element.style.display = 'none';
        });
        
        //设置成0 并且改变消息列表颜色 进入正常状态
    }
}



//删除所有消息
function xxscztqb(){
    if (document.getElementById("xxtzsul").innerText == "0") {
        return;
    }
    if (confirm("确定要删除所有消息吗?")) {
        // 用户点击了确认按钮
      } else {
        // 用户点击了取消按钮或关闭了弹窗
        return;
      }
      
    var ele = window.event.srcElement.id;//获取点击的id
    loadpop("正在删除消息，请稍后...","ok");
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/messg.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('plid=-2'+"&type=-2");
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            //alert(xhr.responseText);
            if (xhr.responseText == "") {
                errorpop("未获取到数据");
                return;
            }
            if (xhr.responseText == "消息已删除") {
                //document.getElementById(xxzt).style.display="none";//隐藏小红点提示  当用户点击消息说明消息已被查看 则隐藏小红点
                //ele.remove();
                //删除页面上的所有消息
                // 获取指定元素  
                var element = document.getElementById("sh-news-con");  
                // 删除所有子元素  
                while (element.firstChild) {  
                  element.removeChild(element.firstChild);  
                }
                
                document.getElementById("xxsczt").lang="0";
                //document.getElementById("xxsczt").className="iconfont icon-shanchu ri-sxhs";
                //document.getElementById("xxscztqbk").style.display="none";
                
                var xxtszajg=0;//数量数字减1
                document.getElementById("xxtzsul").innerText=xxtszajg;//将新结果显示上去
                //删除页面上的对应消息
                document.querySelectorAll('.xiaoxhd').forEach(function (dot) {
                    dot.style.display = "none";
                });
                document.getElementById("js_menu").style.display="none";
                
                successpop("已删除所有消息");
            }else{errorpop(xhr.responseText);}
        }
    };
    event.stopPropagation();//禁止冒泡
}

//已读所有消息
function xxscyd(){
    if (document.getElementById("xxtzsul").innerText == "0") {
        return;
    }
    var ele = window.event.srcElement.id;//获取点击的id
    loadpop("正在已读，请稍后...","ok");
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/messg.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('plid=-3'+"&type=-3");
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            //alert(xhr.responseText);
            if (xhr.responseText == "") {
                errorpop("未获取到数据");
                return;
            }
            if (xhr.responseText == "消息已读") {
                // 获取 id 为 "sh-news-con" 的元素  
                var container = document.getElementById("sh-news-con");  
                // 递归函数，用于遍历元素及其子元素  
                function hideElementsWithPrefix(element, prefix) {  
                  // 遍历元素的子元素  
                  for (var i = 0; i < element.children.length; i++) {  
                    var child = element.children[i];  
                      
                    // 如果子元素的 id 以指定前缀开头，则隐藏该元素  
                    if (child.id.startsWith(prefix)) {  
                      child.style.display = "none";  
                    }  
                      
                    // 递归调用，继续查找子元素的子元素  
                    hideElementsWithPrefix(child, prefix);  
                  }  
                }  
                // 调用函数，隐藏以 "xxztx-" 开头的元素及其子元素  
                hideElementsWithPrefix(container, "xxztx-");
                document.querySelectorAll('.xiaoxhd').forEach(function (dot) {
                    dot.style.display = "none";
                });
                document.getElementById("js_menu").style.display="none";
                
                
                successpop("已读所有消息");
            }else{errorpop(xhr.responseText);}
        }
    };
    event.stopPropagation();//禁止冒泡
}


//查看消息详情按钮事件
function mesgxq(){
    event.stopPropagation();
    
    var ele = window.event.srcElement.id;//获取点击的id
    var elela = window.event.srcElement.lang;//获取点击的id
    //var bt="xxtzidtitle-"+ele;//对得对应标题id
    var nr="xxtzidtext-"+ele;//对得对应内容id
    var xxzt="xxztx-"+ele;//获取消息小红点id
    
    //var btt=document.getElementById(bt).innerText;//取得消息标题内容
    var btt=document.getElementById(nr).lang;//取得消息标题内容
    var nrr=document.getElementById(nr).innerText;//取得消息内容
    
    
    //alert(btt+"\n"+nrr);
        //swal(btt,nrr,'success');//弹窗消息标题与内容
        //document.getElementById(xxzt).style.display="none";//隐藏小红点提示  当用户点击消息说明消息已被查看 则隐藏小红点
        
        var xxsczt=document.getElementById("xxsczt").lang;//获取消息选中状态
        if (xxsczt == 0) {
            //为0则是设置已读状态
            
            if (elela == "#-1") {
                warnpop("此消息已不存在，无法查看");
                return;
            }
                    //设置消息为已读 --提交服务器
        //swal(btt,nrr,'success');//弹窗消息标题与内容
        
        
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/messg.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('plid='+ele+"&type=0");
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            if (xhr.responseText == "") {
                errorpop("未获取到数据");
                return;
            }
            //alert(xhr.responseText);
            if (xhr.responseText == "消息已读") {
                document.getElementById(xxzt).style.display="none";//隐藏小红点提示  当用户点击消息说明消息已被查看 则隐藏小红点
                if (elela != "#-1") {
                   location.href='./view.php?cid='+elela; 
                }
            }else{errorpop(xhr.responseText);}
        }
    };
    
        //设置消息为已读 --提交服务器
        
            //为0则是设置已读状态
        }else if(xxsczt == -1){
            //为-1则是设置删除状态
            //为-1则是设置删除状态 
        }

}



//删除单个消息通知
function demes(){
    if (document.getElementById("xxtzsul").innerText == "0") {
        return;
    }
    var ele = window.event.srcElement.id;//获取点击的id
    loadpop("正在删除消息，请稍后...","ok");
        // 异步对象
    var xhr = new XMLHttpRequest();
    // 设置属性
    xhr.open('post', './api/messg.php');
    // 如果想要使用post提交数据,必须添加此行
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");
    // 将数据通过send方法传递
    xhr.send('plid='+ele+"&type=-1");
    // 发送并接受返回值
    xhr.onreadystatechange = function () {
        // 这步为判断服务器是否正确响应
        if (xhr.readyState == 4 && xhr.status == 200) {
            //alert(xhr.responseText);
            if (xhr.responseText == "") {
                errorpop("未获取到数据");
                return;
            }
            if (xhr.responseText == "消息已删除") {
                //document.getElementById(xxzt).style.display="none";//隐藏小红点提示  当用户点击消息说明消息已被查看 则隐藏小红点
                //ele.remove();
                //删除页面上的对应消息
                var x = document.getElementById(ele);
                //如果对象x不为空
                if (x != null){
                    x.remove();
                }
                
                var xxtsza=document.getElementById("xxtzsul").innerText;//获取当前条数数字内容
                if (xxtsza > 0) {
                    var xxtszajg=xxtsza-1;//数量数字减1
                }else{var xxtszajg=xxtsza;}
                
                document.getElementById("xxtzsul").innerText=xxtszajg;//将新结果显示上去
                //删除页面上的对应消息
                if (xxtszajg <= 0) {
                    document.querySelectorAll('.xiaoxhd').forEach(function (dot) {
                        dot.style.display = "none";
                    });
                }
                
                successpop("消息已删除");
            }else{errorpop(xhr.responseText);}
        }
    };
    event.stopPropagation();//禁止冒泡
}












//获取更多的文章
var LUMINA_LIST_SNAPSHOT_TTL = 30 * 60 * 1000;

function luminaListSnapshotUrl(url) {
    try {
        var parsed = new URL(url || window.location.href, window.location.href);
        parsed.hash = '';
        return parsed.pathname + parsed.search;
    } catch (err) {
        return window.location.pathname + window.location.search;
    }
}

function luminaListSnapshotKey(url) {
    return 'lumina:list:snapshot:' + luminaListSnapshotUrl(url);
}

function luminaListSnapshotCount(root) {
    if (!root || !root.querySelectorAll) return 0;
    return root.querySelectorAll('.sh-homecontent-lie, .sh-homecontent-timed, .sh-content').length;
}

function luminaCreateNodeFromHTML(html) {
    if (!html) return null;
    var template = document.createElement('template');
    template.innerHTML = html.trim();
    return template.content.firstElementChild;
}

function luminaSaveListSnapshot(url) {
    var list = document.getElementById('sh-nrbk');
    if (!list || !window.sessionStorage) return false;

    var more = document.querySelector('.lumina-list-more');
    var nav = document.getElementById('lumina-page-nav');
    var visibleNav = document.querySelector('.lumina-page-nav');
    var payload = {
        url: luminaListSnapshotUrl(url || window.location.href),
        time: Date.now(),
        scrollY: window.pageYOffset || document.documentElement.scrollTop || document.body.scrollTop || 0,
        count: luminaListSnapshotCount(list),
        list: list.outerHTML,
        more: more ? more.outerHTML : '',
        nav: nav ? nav.outerHTML : '',
        visibleNav: visibleNav ? visibleNav.outerHTML : ''
    };

    try {
        window.sessionStorage.setItem(luminaListSnapshotKey(url || window.location.href), JSON.stringify(payload));
        return true;
    } catch (err) {
        return false;
    }
}

function luminaRestoreListSnapshot(options) {
    options = options || {};
    if (!window.sessionStorage) return false;

    var raw = null;
    try {
        raw = window.sessionStorage.getItem(luminaListSnapshotKey(options.url || window.location.href));
    } catch (err) {
        return false;
    }
    if (!raw) return false;

    var payload;
    try {
        payload = JSON.parse(raw);
    } catch (err2) {
        return false;
    }
    if (!payload || !payload.list || Date.now() - (payload.time || 0) > LUMINA_LIST_SNAPSHOT_TTL) {
        try {
            window.sessionStorage.removeItem(luminaListSnapshotKey(options.url || window.location.href));
        } catch (err3) {}
        return false;
    }

    var currentList = document.getElementById('sh-nrbk');
    var snapshotList = luminaCreateNodeFromHTML(payload.list);
    if (!currentList || !snapshotList || !currentList.parentNode) return false;

    if (!options.force && payload.count <= luminaListSnapshotCount(currentList)) {
        if (options.scroll !== false && typeof payload.scrollY === 'number') {
            luminaRestoreListScroll(payload.scrollY);
        }
        return false;
    }

    if (typeof window.luminaDisposePageContent === 'function') {
        window.luminaDisposePageContent(currentList);
    }
    currentList.parentNode.replaceChild(snapshotList, currentList);

    var currentMore = document.querySelector('.lumina-list-more');
    var snapshotMore = luminaCreateNodeFromHTML(payload.more || '');
    if (currentMore && snapshotMore && currentMore.parentNode) {
        currentMore.parentNode.replaceChild(snapshotMore, currentMore);
    }

    var currentNav = document.getElementById('lumina-page-nav');
    var snapshotNav = luminaCreateNodeFromHTML(payload.nav || '');
    if (currentNav && snapshotNav && currentNav.parentNode) {
        currentNav.parentNode.replaceChild(snapshotNav, currentNav);
    }

    var currentVisibleNav = document.querySelector('.lumina-page-nav');
    var snapshotVisibleNav = luminaCreateNodeFromHTML(payload.visibleNav || '');
    if (currentVisibleNav && snapshotVisibleNav && currentVisibleNav.parentNode) {
        currentVisibleNav.parentNode.replaceChild(snapshotVisibleNav, currentVisibleNav);
    }

    if (typeof window.luminaRefreshDynamicContent === 'function') {
        window.luminaRefreshDynamicContent();
    } else if (typeof loaddemand === 'function') {
        loaddemand();
    }

    if (options.scroll !== false && typeof payload.scrollY === 'number') {
        luminaRestoreListScroll(payload.scrollY);
    }
    return true;
}

function luminaRestoreListScroll(scrollY) {
    var y = Math.max(0, parseInt(scrollY, 10) || 0);
    var restore = function () {
        window.scrollTo(0, y);
    };
    restore();
    setTimeout(restore, 80);
    setTimeout(restore, 240);
}

window.luminaSaveListSnapshot = luminaSaveListSnapshot;
window.luminaRestoreListSnapshot = luminaRestoreListSnapshot;

window.addEventListener('pagehide', function () {
    luminaSaveListSnapshot();
});

document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
        luminaSaveListSnapshot();
    }
});

window.addEventListener('pageshow', function (e) {
    var nav = null;
    if (window.performance && typeof window.performance.getEntriesByType === 'function') {
        var entries = window.performance.getEntriesByType('navigation');
        nav = entries && entries.length ? entries[0] : null;
    }
    if (e.persisted || (nav && nav.type === 'back_forward')) {
        setTimeout(function () {
            luminaRestoreListSnapshot({ force: true, scroll: true });
        }, 0);
    }
});

function luminaGetNextUrl(nav) {
    if (!nav) return '';
    var next = nav.querySelector('a[rel="next"], a.next');
    if (next && next.href) return next.href;
    var links = Array.prototype.slice.call(nav.querySelectorAll('a'));
    var currentText = '';
    var current = nav.querySelector('.current, .active, span');
    if (current) {
        currentText = (current.textContent || '').trim();
    }
    if (currentText !== '') {
        for (var i = 0; i < links.length; i++) {
            var txt = (links[i].textContent || '').trim();
            if (txt === currentText && links[i + 1]) {
                return links[i + 1].href;
            }
        }
    }
    for (var j = 0; j < links.length; j++) {
        var label = (links[j].textContent || '').trim();
        if (/下一页|下页|›|»|Next/i.test(label)) {
            return links[j].href;
        }
    }
    return '';
}

function luminaUpdateNextUrlFromNav(nav) {
    var holder = document.getElementById('lumina-page-nav');
    if (!holder) return '';
    if (nav) {
        holder.innerHTML = nav.innerHTML;
        ['data-page-base', 'data-current-page', 'data-total-pages'].forEach(function (name) {
            var value = nav.getAttribute(name);
            if (value !== null) holder.setAttribute(name, value);
            else holder.removeAttribute(name);
        });
        var dataNext = nav.getAttribute('data-next');
        if (dataNext) {
            holder.setAttribute('data-next', dataNext);
            return dataNext;
        }
    }
    return luminaGetNextUrl(holder);
}

function luminaResolveLoadMoreUrl(navHolder, footer) {
    var candidates = [
        footer ? footer.getAttribute('data-next') : '',
        navHolder ? navHolder.getAttribute('data-next') : '',
        luminaGetNextUrl(navHolder)
    ];
    for (var i = 0; i < candidates.length; i++) {
        var candidate = String(candidates[i] || '').trim();
        if (!candidate) continue;
        try {
            var url = new URL(candidate, window.location.href);
            if (url.href !== window.location.href) return url.href;
        } catch (err) {}
    }

    if (!navHolder) return '';
    var base = String(navHolder.getAttribute('data-page-base') || '').trim();
    var current = parseInt(navHolder.getAttribute('data-current-page') || '1', 10);
    var total = parseInt(navHolder.getAttribute('data-total-pages') || '1', 10);
    if (!base || !isFinite(current) || !isFinite(total) || current >= total) return '';
    try {
        return new URL(base + (current + 1), window.location.href).href;
    } catch (err2) {
        return '';
    }
}

function luminaInitListLoadMore() {
    var footer = document.getElementById('footer-text-zt');
    if (!footer || !('IntersectionObserver' in window)) return;
    if (window.luminaListLoadObserver) {
        window.luminaListLoadObserver.disconnect();
    }
    window.luminaListLoadObserver = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting && typeof window.luminaLoadMore === 'function') {
                window.luminaLoadMore();
            }
        });
    }, { rootMargin: '360px 0px' });
    window.luminaListLoadObserver.observe(footer);
}
window.luminaInitListLoadMore = luminaInitListLoadMore;

function luminaLoadMore() {
    var footer = document.getElementById("footer-text-zt");
    if (!footer) return;
    var setState = function (state) {
        footer.setAttribute('data-state', state);
        if (state === 'loading') {
            footer.innerText = '正在加载';
            footer.style.animation = 'colorChange 0.8s infinite';
            return;
        }
        footer.removeAttribute('style');
        if (state === 'done') {
            footer.innerText = '没有更多了';
            return;
        }
        footer.innerText = '查看更多';
    };
    var currentState = footer.getAttribute('data-state') || 'idle';
    if (currentState === 'done' || currentState === 'loading') return;

    var navHolder = document.getElementById('lumina-page-nav');
    var nextUrl = luminaResolveLoadMoreUrl(navHolder, footer);
    if (!nextUrl) {
        setState('done');
        return;
    }

    setState('loading');

    fetch(nextUrl, { credentials: 'same-origin' })
        .then(function (res) { return res.text(); })
        .then(function (html) {
            var parser = new DOMParser();
            var doc = parser.parseFromString(html, 'text/html');
            var newWrap = doc.getElementById('sh-nrbk');
            if (!newWrap) {
                setState('done');
                return;
            }
            var authorTarget = document.querySelector('.lumina-author-list');
            var newAuthorWrap = newWrap.querySelector('.lumina-author-list');
            var isAuthorList = !!(authorTarget && newAuthorWrap);
            var isHome = document.querySelector('.sh-homecontent') !== null;
            var items;
            var target = document.getElementById('sh-nrbk');
            if (isAuthorList) {
                target = authorTarget;
                items = Array.prototype.filter.call(newAuthorWrap.children, function (node) {
                    return node.classList && (
                        node.classList.contains('sh-homecontent-timed') ||
                        node.classList.contains('sh-homecontent-lie')
                    );
                });
            } else {
                items = isHome ? newWrap.querySelectorAll('.sh-homecontent-lie') : newWrap.querySelectorAll('.sh-content');
            }
            if (!items || items.length === 0) {
                setState('done');
                return;
            }
            items.forEach(function (node) {
                target.appendChild(node);
            });
            var newNav = doc.getElementById('lumina-page-nav');
            var updated = luminaUpdateNextUrlFromNav(newNav);
            if (navHolder) {
                navHolder.setAttribute('data-next', updated || '');
            }
            footer.setAttribute('data-next', updated || '');
            setState(updated ? 'idle' : 'done');
            luminaSaveListSnapshot();
            if (typeof window.luminaRefreshDynamicContent === 'function') {
                window.luminaRefreshDynamicContent();
            } else {
                loaddemand();
                if (typeof wzcsql === 'function') { wzcsql(); }
                if (typeof luminaInitMusicCards === 'function') { luminaInitMusicCards(); }
            }
        })
        .catch(function () {
            setState('idle');
        });
}

function hqgd(){
    luminaLoadMore();
}
window.hqgd = hqgd;
window.luminaLoadMore = luminaLoadMore;
luminaInitListLoadMore();















/* 首页顶部音乐播放控制 */





//首页顶部音乐随机获取




    
document.oncontextmenu=new Function("event.returnValue=false"); //禁止右键



        
//设置评论框高度自适应
function autoResizeTextarea(element) {
  element.style.height = 'auto';
  element.style.height = `${element.scrollHeight}px`;
}
// 获取Textarea元素
var textarea = document.getElementById('bletext');
// 监听输入事件，当内容发生变化时调用自动调整函数
textarea.addEventListener('input', function() {
    autoResizeTextarea(this);
});
//评论框内容改变监听







//全文按钮事件
function quanwenan(btn, evt){
    btn = btn || (evt && (evt.currentTarget || evt.target)) || (window.event && (window.event.target || window.event.srcElement)) || null;
    if (!btn) return false;
    if (evt && typeof evt.preventDefault === 'function') {
        evt.preventDefault();
    }

    var previewId = btn.getAttribute('data-preview');
    var fullId = btn.getAttribute('data-full');
    if (previewId && fullId) {
        var preview = document.getElementById(previewId);
        var full = document.getElementById(fullId);
        if (!preview || !full) return false;
        var opened = btn.getAttribute('data-open') === '1';
        if (opened) {
            full.style.display = 'none';
            preview.style.display = '';
            btn.setAttribute('data-open', '0');
            btn.innerText = '全文';
        } else {
            preview.style.display = 'none';
            full.style.display = '';
            btn.setAttribute('data-open', '1');
            btn.innerText = '收起';
        }
        return false;
    }

    var ele = btn.id || '';
    var elelang = btn.lang;
    var quanwid = ele.replace("sh-content-quanwenan-","");
    var detailBlock = document.getElementById("sh-content-qwdid-"+quanwid);
    var detailBtn = document.getElementById("sh-content-quanwenan-"+quanwid);
    if (!detailBlock || !detailBtn) {
        return false;
    }

    if(elelang == 0){
        var dqdcla = detailBlock.className;//获取当前class
        var re = new RegExp("wzndhycyc","g"); //定义正则表达式
        var Newdqdcla = dqdcla.replace(re, ""); //替换指定class
        detailBlock.className=Newdqdcla;
        detailBtn.lang=1;
        detailBtn.innerText="收起";
    }else if(elelang == 1){
        detailBlock.className +="wzndhycyc";
        detailBtn.lang=0;
        detailBtn.innerText="全文";
    }

    return false;
}





//音乐悬浮窗拖动
if (document.getElementById('musicbc') && document.getElementById('musicbc').lang == 1) {
var draggable = document.getElementById('yszt');
var draggable2 = document.getElementById('musicbc');
var isDragging = false;
var offset = { x: 0, y: 0 };

draggable.addEventListener('mousedown', startDragging);
draggable.addEventListener('touchstart', startDragging);

document.addEventListener('mousemove', drag);
document.addEventListener('touchmove', drag);

document.addEventListener('mouseup', stopDragging);
document.addEventListener('touchend', stopDragging);

function startDragging(e) {
    e.preventDefault();
    isDragging = true;

    var rect = draggable.getBoundingClientRect();
    var clientX = e.type === 'touchstart' ? e.touches[0].clientX : e.clientX;
    var clientY = e.type === 'touchstart' ? e.touches[0].clientY : e.clientY;
    offset.x = clientX - rect.left;
    offset.y = clientY - rect.top;
}

function drag(e) {
    if (!isDragging) return;

    var clientX = e.type === 'touchmove' ? e.touches[0].clientX : e.clientX;
    var clientY = e.type === 'touchmove' ? e.touches[0].clientY : e.clientY;
    var x = clientX - offset.x;
    var y = clientY - offset.y;

    var screenWidth = window.innerWidth || document.documentElement.clientWidth || document.body.clientWidth;
    var screenHeight = window.innerHeight || document.documentElement.clientHeight || document.body.clientHeight;

    x = Math.max(0, Math.min(x, screenWidth - draggable2.offsetWidth));
    y = Math.max(0, Math.min(y, screenHeight - draggable2.offsetHeight));

    draggable2.style.top = y + 'px';
}

function stopDragging() {
    isDragging = false;
}
}
















var dayBtn = document.getElementById('day');
if (dayBtn && dayBtn.getAttribute('data-bind') !== 'theme') {
  dayBtn.addEventListener('click', function() {
    var day=dayBtn.lang;
    var body = document.querySelector('body');
    if (day == 1) {
        body.classList.toggle('dark-theme');
        dayBtn.lang="0";
        document.getElementById("day-i").className="iconfont icon-yueliang";
        document.cookie = "dark_theme=dark-theme";
        
    }else if (day == 0){
        body.classList.toggle('dark-theme');
        dayBtn.lang="1";
        document.getElementById("day-i").className="iconfont icon-ai250";
        document.cookie = "dark_theme=root";
    }
  });
}



//回到顶部
function scrollToTop() {
  window.scrollTo({
    top: 0,
    behavior: 'smooth'
  });
}





function loaddemand(){
const images = document.querySelectorAll('img')

const callback = entries => {
  entries.forEach(entry => {
    if (entry.isIntersecting) {
      const imgae = entry.target
      const data_src = imgae.getAttribute('data-src')
      if (!data_src || data_src === 'null') {
        observer.unobserve(imgae)
        return
      }
      imgae.setAttribute('src', data_src)
      observer.unobserve(imgae)
    }
  })
}

const observer = new IntersectionObserver(callback, { rootMargin: '600px 0px' })

images.forEach(imgae => {
  observer.observe(imgae)
})

}


loaddemand();//调用一次懒加载

document.addEventListener('DOMContentLoaded', function(){
    var captcha = document.getElementById('captcha');
    if (captcha) {
        captcha.addEventListener('click', function(){
            var base = this.getAttribute('src').split('?')[0];
            this.setAttribute('src', base + '?t=' + Date.now());
        });
    }
});

// 展开/收起正文
function luminaToggleText(el){
    if (!el) return;
    var targetId = el.getAttribute('data-target');
    if (!targetId) return;
    var block = document.getElementById(targetId);
    if (!block) return;
    if (block.classList.contains('wzndhycyc')) {
        block.classList.remove('wzndhycyc');
        el.textContent = '收起';
    } else {
        block.classList.add('wzndhycyc');
        el.textContent = '展开';
    }
}

// 消息盒子（本地交互，不依赖接口）
function luminaNoticeList() {
    return document.querySelectorAll('#sh-news-con .sh-news-con-lie');
}

function luminaNoticeBadge() {
    return document.getElementById('lumina-notice-badge');
}

function luminaNoticeSyncBadge() {
    var badge = luminaNoticeBadge();
    if (!badge) return;
    badge.style.display = luminaNoticeUnreadCount() > 0 ? '' : 'none';
}

function luminaNoticeUnreadCount() {
    var count = 0;
    luminaNoticeList().forEach(function (item) {
        var dot = item.querySelector('.xiaoxhd');
        if (dot && dot.style.display !== 'none') {
            count++;
        }
    });
    return count;
}

function luminaNoticeUpdateCount() {
    var countEl = document.getElementById('xxtzsul');
    var unreadEl = document.getElementById('xxtzwd');
    var total = luminaNoticeList().length;
    var unread = luminaNoticeUnreadCount();
    if (countEl) {
        countEl.innerText = total;
    }
    if (unreadEl) {
        unreadEl.innerText = unread;
    }
}

function luminaNoticeStorageKey() {
    return 'lumina_notice_read';
}

function luminaNoticeBackendDelete(keys) {
    if (!keys || !keys.length) {
        return Promise.resolve();
    }
    if (!window.LUMINA_NOTICE_URL || !window.LUMINA_NOTICE_TOKEN) {
        return Promise.resolve();
    }
    var fd = new FormData();
    fd.append('lumina_action', 'notice_delete');
    fd.append('token', window.LUMINA_NOTICE_TOKEN);
    keys.forEach(function (key) {
        fd.append('keys[]', key);
    });
    return fetch(window.LUMINA_NOTICE_URL, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin'
    }).then(function (res) {
        return res.json();
    }).catch(function () {
        return null;
    });
}

function luminaNoticeDeletedKey() {
    return 'lumina_notice_deleted';
}

function luminaNoticeGetReadMap() {
    try {
        var raw = localStorage.getItem(luminaNoticeStorageKey());
        if (!raw) return {};
        var map = JSON.parse(raw);
        return map && typeof map === 'object' ? map : {};
    } catch (e) {
        return {};
    }
}

function luminaNoticeSaveReadMap(map) {
    try {
        localStorage.setItem(luminaNoticeStorageKey(), JSON.stringify(map || {}));
    } catch (e) {}
}

function luminaNoticeGetDeletedMap() {
    try {
        var raw = localStorage.getItem(luminaNoticeDeletedKey());
        if (!raw) return {};
        var map = JSON.parse(raw);
        return map && typeof map === 'object' ? map : {};
    } catch (e) {
        return {};
    }
}

function luminaNoticeSaveDeletedMap(map) {
    try {
        localStorage.setItem(luminaNoticeDeletedKey(), JSON.stringify(map || {}));
    } catch (e) {}
}

function luminaNoticeMarkRead(item) {
    if (!item) return;
    var key = item.getAttribute('data-key');
    if (!key) return;
    var map = luminaNoticeGetReadMap();
    map[key] = 1;
    luminaNoticeSaveReadMap(map);
    var dot = item.querySelector('.xiaoxhd');
    if (dot) {
        dot.style.display = 'none';
    }
    luminaNoticeSyncBadge();
}

function luminaNoticeMarkDeleted(item) {
    if (!item) return;
    var key = item.getAttribute('data-key');
    if (!key) return;
    var map = luminaNoticeGetDeletedMap();
    map[key] = 1;
    luminaNoticeSaveDeletedMap(map);
    item.remove();
    luminaNoticeSyncBadge();
}

function luminaNoticeApplyRead() {
    var map = luminaNoticeGetReadMap();
    var delMap = luminaNoticeGetDeletedMap();
    var items = luminaNoticeList();
    items.forEach(function (item) {
        var key = item.getAttribute('data-key');
        if (key && delMap[key]) {
            item.remove();
            return;
        }
        if (key && map[key]) {
            var dot = item.querySelector('.xiaoxhd');
            if (dot) {
                dot.style.display = 'none';
            }
        }
    });
    luminaNoticeUpdateCount();
    luminaNoticeSyncBadge();
}

window.luminaNoticeApplyRead = luminaNoticeApplyRead;

function js_menu() {
    var adElement = document.getElementById('js_menu');
    if (!adElement) return;
    var isAdVisible = adElement.style.display === 'none' || adElement.style.display === '';
    adElement.style.display = isAdVisible ? 'block' : 'none';
}

function xxsczt() {
    event.stopPropagation();
    var toggle = document.getElementById('xxsczt');
    var container = document.getElementById('sh-news-con');
    if (!toggle || !container) return;
    var selecting = toggle.lang === '-1';
    if (selecting) {
        toggle.lang = '0';
        toggle.innerText = '选择消息';
        container.classList.remove('lumina-news-select');
        container.querySelectorAll('.lumina-news-selected').forEach(function (el) {
            el.classList.remove('lumina-news-selected');
        });
    } else {
        toggle.lang = '-1';
        toggle.innerText = '取消选择';
        container.classList.add('lumina-news-select');
    }
    var menu = document.getElementById('js_menu');
    if (menu) menu.style.display = 'none';
}

function xxscztqb() {
    if (!confirm("确定要删除所有消息吗?")) {
        return;
    }
    var container = document.getElementById("sh-news-con");
    if (!container) return;
    var backendKeys = [];
    var map = luminaNoticeGetDeletedMap();
    var items = container.querySelectorAll('.sh-news-con-lie');
    items.forEach(function (item) {
        var key = item.getAttribute('data-key');
        if (key) {
            map[key] = 1;
            backendKeys.push(key);
        }
    });
    luminaNoticeSaveDeletedMap(map);
    if (backendKeys.length) {
        luminaNoticeBackendDelete(backendKeys);
    }
    container.innerHTML = '';
    luminaNoticeUpdateCount();
    var menu = document.getElementById('js_menu');
    if (menu) menu.style.display = 'none';
    var toggle = document.getElementById('xxsczt');
    if (toggle) {
        toggle.lang = '0';
        toggle.innerText = '选择消息';
    }
    container.classList.remove('lumina-news-select');
}

function xxscztSelected() {
    event.stopPropagation();
    var container = document.getElementById('sh-news-con');
    if (!container) return;
    var selected = container.querySelectorAll('.lumina-news-selected');
    if (!selected.length) {
        if (typeof warnpop === 'function') {
            warnpop('请选择要删除的消息');
        }
        var menu = document.getElementById('js_menu');
        if (menu) menu.style.display = 'none';
        return;
    }
    if (!confirm('确定删除所选消息吗?')) {
        return;
    }
    var backendKeys = [];
    selected.forEach(function (item) {
        var key = item.getAttribute('data-key');
        if (key) {
            backendKeys.push(key);
        }
        luminaNoticeMarkDeleted(item);
    });
    if (backendKeys.length) {
        luminaNoticeBackendDelete(backendKeys);
    }
    luminaNoticeUpdateCount();
    var menu = document.getElementById('js_menu');
    if (menu) menu.style.display = 'none';
}

function xxscyd() {
    var items = luminaNoticeList();
    items.forEach(function (item) {
        luminaNoticeMarkRead(item);
    });
    var menu = document.getElementById('js_menu');
    if (menu) menu.style.display = 'none';
}

function mesgxq() {
    event.stopPropagation();
    var selecting = document.getElementById('xxsczt') && document.getElementById('xxsczt').lang === '-1';
    var item = event.target.closest('.sh-news-con-lie');
    if (!item) return;
    if (selecting) {
        item.classList.toggle('lumina-news-selected');
        return;
    }
    luminaNoticeMarkRead(item);
    var href = item.getAttribute('data-href') || item.getAttribute('lang');
    if (href) {
        window.location.href = href;
    }
}

function demes() {
    event.stopPropagation();
    var item = event.target.closest('.sh-news-con-lie');
    if (!item) return;
    var key = item.getAttribute('data-key');
    luminaNoticeMarkDeleted(item);
    if (key) {
        luminaNoticeBackendDelete([key]);
    }
    luminaNoticeUpdateCount();
}

document.addEventListener('DOMContentLoaded', function () {
    luminaNoticeApplyRead();
});


