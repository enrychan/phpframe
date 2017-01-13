/*
 * 使用说明:
 * window.wxc.Pop(popHtml, [type], [options])
 * popHtml:html字符串
 * type:window.wxc.xcConfirm.typeEnum集合中的元素
 * options:扩展对象
 * 用法:
 * 1. window.wxc.xcConfirm("我是弹窗<span>lalala</span>");
 * 2. window.wxc.xcConfirm("成功","success");
 * 3. window.wxc.xcConfirm("请输入","input",{onOk:function(){}})
 * 4. window.wxc.xcConfirm("自定义",{title:"自定义"})
 */
(function($){
	window.wxc = window.wxc || {};
	window.wxc.xcConfirm = function(popHtml,back) {
		var options;
	  var btnType = window.wxc.xcConfirm.btnEnum;
		var eventType = window.wxc.xcConfirm.eventEnum;
		var popType = {};
		var config = $.extend(true, {
			//属性
			title: "温馨提示", //自定义的标题
			icon: "", //图标
			btn: btnType.ok, //按钮,默认单按钮
			//事件
			btnn:btnType.cancel,
			onOk: $.noop,//点击确定的按钮回调
			onCancel: $.noop,//点击取消的按钮回调
			onClose: $.noop//弹窗关闭的回调,返回触发事件
		}, back);


		var $txt = $("<p>").html(popHtml);//弹窗文本dom
		var $tt = $("<span>").addClass("tt").text(config.title);//标题
		var icon = config.icon;
		// var $icon = icon ? $("<div>").addClass("bigIcon").css("backgroundPosition",icon) : "";
		var btn = config.btn;//按钮组生成参数
		var btnn = config.btnn;//按钮组生成参数

		var popId = creatPopId();//弹窗索引

		var $box = $("<div>").addClass("xcConfirm");//弹窗插件容器
		var $layer = $("<div>").addClass("xc_layer");//遮罩层
		var $popBox = $("<div>").addClass("popBox");//弹窗盒子
		var $ttBox = $("<div>").addClass("ttBox");//弹窗顶部区域
		var $txtBox = $("<div>").addClass("txtBox");//弹窗内容主体区
		var $btnArea = $("<div>").addClass("btnArea");//按钮区域

		var $ok = $("<a>").addClass("sgBtn").addClass("ok").text("确定");//确定按钮
		var $cancel = $("<a>").addClass("sgBtn").addClass("cancel").text("取消");//取消按钮
		var $input = $("<input>").addClass("inputBox");//输入框
		//var $clsBtn = $("<a>").addClass("clsBtn");//关闭按钮

		//建立按钮映射关系
		var btns = {
			ok: $ok,
			cancel: $cancel
		};

		init();

		function init(){


			creatDom();
			bind();
		}

		function creatDom(){
			$popBox.append(
				// $ttBox.append(
				// 	$clsBtn
				// ).append(
				// 	$tt
				// )
				$ttBox.append(
					$tt
				)
			).append(
				// $txtBox.append($icon).append($txt)
				$txtBox.append($txt)
			).append(
				$btnArea.append(creatBtnGroup(btn))
			).append(
				$btnArea.append(creatBtnGroup(btnn))
			);
			$box.attr("id", popId).append($layer).append($popBox);
			$("body").append($box);
		}

		function bind(){
			//点击确认按钮
			if(back){
				$ok.click(function(){window.location.href=back;});
			}else{
				$ok.click(doCancel);
			}




			//回车键触发确认按钮事件
			$(window).bind("keydown", function(e){
				if(e.keyCode == 13) {
					if($("#" + popId).length == 1){
						doOk();
					}
				}
			});

			//点击取消按钮
			$cancel.click(doCancel);

			//点击关闭按钮
			// $clsBtn.click(doClose);
		}

		//确认按钮事件
		function doOk(){
			var $o = $(this);
			var v = $.trim($input.val());
			if ($input.is(":visible"))
		        config.onOk(v);
		    else
		        config.onOk();
			$("#" + popId).remove();
			config.onClose(eventType.ok);
		}

		//取消按钮事件
		function doCancel(){
			var $o = $(this);
			config.onCancel();
			$("#" + popId).remove();
			config.onClose(eventType.cancel);
		}

		//关闭按钮事件
		function doClose(){
			$("#" + popId).remove();
			config.onClose(eventType.close);
			$(window).unbind("keydown");
		}

		//生成按钮组
		function creatBtnGroup(tp){
			var $bgp = $("<div>").addClass("btnGroup");
			$.each(btns, function(i, n){
				if( btnType[i] == (tp & btnType[i]) ){
					$bgp.append(n);
				}
			});
			return $bgp;
		}

		//重生popId,防止id重复
		function creatPopId(){
			var i = "pop_" + (new Date()).getTime()+parseInt(Math.random()*100000);//弹窗索引
			if($("#" + i).length > 0){
				return creatPopId();
			}else{
				return i;
			}
		}
	};

	//按钮类型
	window.wxc.xcConfirm.btnEnum = {
		ok: parseInt("0001",2), //确定按钮
		cancel: parseInt("0010",2), //取消按钮
		okcancel: parseInt("0011",2) //确定&&取消
	};

	//触发事件类型
	window.wxc.xcConfirm.eventEnum = {
		ok: 1,
		cancel: 2,
		close: 3
	};

	//弹窗类型
	window.wxc.xcConfirm.typeEnum = {
		asa:"asa",
		info: "info",
		forget_success: "forget_success",
		error:"error",
		login_error:"login_error",
		forget_error:"forget_error",
		confirm: "confirm",
		warning: "warning",
		input: "input",
		custom: "custom"
	};

})(jQuery);