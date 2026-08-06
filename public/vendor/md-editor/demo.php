<!doctype html>
<html lang="zh-CN">
<head>
<meta charset="utf-8">
<title>MD Editor UMD Demo</title>
<link rel="stylesheet" href="mdeditor.min.css">
</head>
<body style="padding:20px;background:#fff">
<h3>Demo: @uiw/react-md-editor (react18 内置)</h3>
<div id="editor"></div>
<pre id="out" style="margin-top:12px;background:#f5f5f5;padding:10px"></pre>
<script src="pafish-md-editor.min.js"></script>
<script>
  var MDEditor = window.MDEditor;
  document.getElementById('out').textContent =
    'MDEditor=' + typeof MDEditor + ' default=' + typeof MDEditor.default;
  var Comp = MDEditor.default || MDEditor;
  var root = ReactDOM.createRoot(document.getElementById('editor'));
  root.render(React.createElement(Comp, {
    value: '# 标题\n\n这是 **粗体** 测试。\n\n- 列表一\n- 列表二\n\n```js\nconsole.log(1);\n```',
    height: 400,
    onChange: function (v) { document.getElementById('out').textContent = 'LEN=' + (v || '').length; }
  }));
</script>
</body>
</html>
