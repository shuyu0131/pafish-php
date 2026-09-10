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
  
          
          if (document.getElementById("top-right-2")) {//判断消息按钮是否存在
              document.getElementById("top-right-2").className = "iconfont icon-a31shezhi al-sxbh";
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
  
  
          if (document.getElementById("top-right-2")) {//判断消息按钮是否存在
              document.getElementById("top-right-2").className = "iconfont iconfont icon-a31shezhi al-sxb";
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
          
          var content= document.getElementById('yszt');
          content.dataset.src=naf;
          var content= document.getElementById('ming');
          content.dataset.src=naf;
          
          
          var bfz=document.getElementById("musicplay").lang;//传完后再次获得播放状态
          
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
  
  
  
  function bfpy(){//播放与暂停
      var ele=document.getElementById("musicplay").className;
      var bfkztu="sh-aud-left-plak-"+ele;//获得文章播放控制按钮
      var bfz=document.getElementById("musicplay").lang;//获得独立播放器播放状态
      var audio = document.getElementById("musicplay");//取到独立音乐播放器
      
      
      if (bfz == 0) {//0为未播放
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
          document.getElementById(bfkztu).lang="0";//更新播放状态为未播放
          document.getElementById(bfkztu).className="iconfont icon-jixu";//暂停播放后设置播放按钮图片为开始的图片
          document.getElementById("sh-musiccz-zb").className="iconfont icon-jixu ri-z-sx"//开始播放设置独立音乐播放器为开始标
          document.getElementById("musicplay").lang ="1"//将播放器状态设为播放中
          document.getElementById("sh-aud-left-plak-"+document.getElementById("musicplay").className).className="iconfont icon-jixu"
      }
  }
  
  
  
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
  
  
  function mbpy(){
      if(document.getElementById("musicbc").lang == 1){
          return;
      }
      var elez = document.getElementById("yszt").lang;
      if(elez == 0){
          const myDiv = document.getElementById('musicbc');
          myDiv.style.transform = 'translateX(-80px)';
          document.getElementById("yszt").lang="1";
      }else{
          const myDiv = document.getElementById('musicbc');
          myDiv.style.transform = 'translateX(0px)';
          document.getElementById("yszt").lang="0";
      }
  }
  
  
  
  
  
  
  
  
  
  
  
function wzcsql(){
var timedElements = document.getElementsByClassName('sh-homecontent-timed');
var timedElementsArray = Array.from(timedElements);
var seenYears = {};

timedElementsArray.forEach((element, index) => {
  var year = element.getAttribute('data-author-year') || (element.textContent || '').replace(/\D/g, '');
  if (!year) {
    return;
  }
  if (seenYears[year]) {
    element.remove();
    return;
  }
  seenYears[year] = true;
});

var elements = document.getElementsByClassName('sh-homecontent-left-time');
var langValues = [];
var duplicateIndexes = [];

for (var i = 0; i < elements.length; i++) {
  var lang = elements[i].lang;

  if (langValues.includes(lang)) {
    duplicateIndexes.push(i);
  } else {
    langValues.push(lang);
  }
}
for (var j = 0; j < elements.length; j++) {
  if (duplicateIndexes.includes(j)) {
    elements[j].style.display = 'none';
    elements[j].lang = '';
  }
}

var elements = document.querySelectorAll('.sh-homecontent-left-time');
for (var i = 0; i < elements.length; i++) {
  if (window.getComputedStyle(elements[i]).display !== 'none') {
    elements[i].parentNode.parentNode.style.marginTop = '25px';
  }
}

document.querySelectorAll(".homecontent-left-time-h, .homecontent-left-time-y").forEach(function(element) {
    element.style.color = "var(--textqh)";
});

}
  
function hqgd(){
    if (typeof luminaLoadMore === 'function') {
        luminaLoadMore();
    }
}
  
  


document.oncontextmenu=new Function("event.returnValue=false"); //禁止右键







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
        if (data_src && data_src !== 'null') {
          imgae.setAttribute('src', data_src)
        }
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
