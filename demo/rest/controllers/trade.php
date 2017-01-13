<<<<<<< .mine
<?php
defined('IN_PHPFRAME') or exit('No permission resources.');
pc_base::load_sys_class('BaseAction');

class trade extends BaseAction
{
	public function __construct()
	{
		//登录鉴权
	    /*
		$_SESSION['user_id'] = '695567515';*/
		parent::__construct();
		self::create_price();
		self::create_sqs();
	}

	

	//交易页面数据
	public function index()
	{
		//商品信息
		$g_code = trim(getgpc("g_code"));
		$cate = trim(getgpc("cate"));
		$goods = D("Rest")->query("select user_id,code,title,price,up,down,num,holdnum,surplus_num from `jjs_goods_release` where code = '".$g_code."'");
		//手续费比例(百分比可在后台修改)
		$para = D("Rest")->query("select * from	`jjs_deal_setting`");
		$fee = (($para[0]["fee"]/2)*100)."%";
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				foreach($pricetables as $k=>$v){
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$g_code."' order by ctime desc limit 1");
					if($deal[0]["price"]) $price[] = $deal[0]["price"];
				}

				if(end($price)){
					//有交易记录
					$newprice = end($price);//最新价
				}else{
					//无交易记录
					$newprice = $vo["price"];//最新价
				}
				
				if($cate == "卖"){

					$coin = D("Rest")->query("select coin from `jjs_user_finance` where user_id = ".$_SESSION["user_id"]);
					$entrust = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and status = 0 and cate = 1 and is_del = 0");//委托卖出但未成交且未撤销的
					$dealnum = $coin[0]["coin"] - $entrust[0]["quantity"];//可卖数量
						
					//交易总额
					$total = $newprice*$dealnum;
				}else{//买
					$dealnum = $vo["holdnum"];
					//交易总额
					$total = $newprice*$vo["holdnum"];

				}
				$goods[$key]["newprice"] = $newprice;//最新价
				$goods[$key]["dealnum"] = $dealnum;//可交易数量
				$goods[$key]["cate"] = $cate;//交易类型
				$goods[$key]["total"] = $total;//总额
				$goods[$key]["fee"] = $fee;//手续费
				
			}
		}
		
		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		
	}

	//交易买入
	public function buy()
	{
	    if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$restModel=D("Rest");
		$g_code = trim(getgpc("g_code"));
	    $quantity = intval(getgpc('quantity'));//委托数量
	    $price = getgpc('price');//委托价
	    $tpassword = md5(getgpc("tpassword"));
		$parameter = D("Rest")->query("select * from `jjs_deal_setting`");
		//商品信息
		$goods = D("Rest")->query("select user_id,code,title,price,up,down,num,holdnum,surplus_num,ctime from `jjs_goods_release` where code = '".$g_code."'");
		$nowtime = time();
		$opentime = strtotime(date("Y-m-d 08:00:00",time()));
		$closetime = strtotime(date("Y-m-d 17:00:00",time()));
		if($nowtime < $opentime){
			returnJson("500","今日暂未开盘");
		}elseif($nowtime > $closetime){
			returnJson("500","今日已收盘");
		}
		if($_SESSION["user_id"] == $goods[0]["user_id"]){
			returnJson("500","您是交易商，不能进行买入交易");
		}
		if($quantity<1){
			returnJson("500","委托数量不合法");
		}
		//判断所传数量是否正确
		if($quantity <= $goods[0]["holdnum"])
		{
			//判断所传价格是否介于跌停和涨停价之间
			if($goods[0]["down"]<$price && $price<$goods[0]["up"]){
				//交易总额=持仓总额+千分之二点五持仓手续费 by enry at170111
				$total = $quantity*$price*(1+0.0025);
				//用户帐户余额查询
				$account = $restModel->query("select * from `jjs_user_finance` where user_id = ".$_SESSION["user_id"]);
				$available = $account[0]["recharge"]+$account[0]["inamount"]+$account[0]["extendamount"]+$account[0]["tempamount"]-$account[0]["withdraw"]-$account[0]["bond"]-$account[0]["outamount"];
				
				$user_tpassword = $restModel->query("select tpassword from `jjs_user` where id = ".$_SESSION["user_id"]);

				if($tpassword == $user_tpassword[0]["tpassword"]){//判断交易密码是否正确
					if($total <= $available){
						//将总交易额移至申购账户############持仓时资金变化  Start##############
						$restModel->querysql("update `jjs_user_finance` set bond = bond + ".$total." where user_id = ".$_SESSION["user_id"]);
						$restModel->query('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18010","持仓可用余额","-'.$total.'","'.date("Y-m-d H:i:s").'")');
						$restModel->query('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18011","持仓保证金","'.$total.'","'.date("Y-m-d H:i:s").'")');
						//将总交易额移至申购账户############持仓时资金变化  Over##############
						
						//上一笔成交价格
						$prerecord = $restModel->query("select t_price from `jjs_contract` where g_code = '".$g_code."' and status == 1 order by deal_time desc limit 1");
						if($prerecord[0]["t_price"])$pre_price = $prerecord[0]["t_price"];else $pre_price = $goods[0]["price"];
	
						//持仓撮合流程 交易原则：价格&时间优先#####################Start#######//by enry at170111
						$quantity_sqs = $quantity;
						$sqstime = time();
						$sqsrs = $restModel->query("select * from `jjs_contract` where cate=1 and ctime like '%".date("Y-m-d",$time)."%' and g_code='".$g_code."' and status=0 and price<='".$price."' order by ctime asc ");//获取本积分有效平仓队列
						if(count($sqsrs)==0)//队列为空
						{
							//同时写入持仓记录:已报
							$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,status,ctime) values('".$_SESSION["user_id"]."','".$g_code."','".$type."','".$quantity."','".$price."','0','".date("Y-m-d H:i:s",$time)."')");
							
							returnJson('200','操作成功');
						}
						
						if($quantity <= count($sqsrs))//队列充足
						{
							foreach($sqsrs as $row){
								
										$sqsdate = file_get_contents('http://apistore.51daniu.cn:1218/?charset=utf-8&name='.$g_code."-S-".$row["price"]."-".$row["user_id"]."-".strtotime($row["ctime"]).'&opt=get&auth=adminchen5188jjs');
										if($sqsdate == 'HTTPSQS_GET_END'){
											break;
										}else{
											$quantity_sqs--;
											$sqsdateArr = explode("-",$sqsdate);
											//最新成交价公式
											$solderID = $sqsdateArr[3];//卖家UID
											$solderPrice = $sqsdateArr[2];//平仓价
											if($price <= $pre_price) $newdealprice = $price;
											if($row["price"] >= $pre_price) $newdealprice = $row["price"];
											if($row["price"] < $pre_price && $pre_price < $price) $newdealprice = $pre_price;
											//卖家处理
											D("Rest")->querysql("insert into `jjs_shares`(user_id,g_code,cate,quantity,price,ctime) values('".$solderID."','".$g_code."','1','1','".$newdealprice."','".date("Y-m-d H:i:s")."')");
											$pre = $restModel->query("select tuser_code from `jjs_user` where id = ".$solderID);
											$pre1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre[0]["tuser_code"]."'");
											//将交易额结算给卖家############减仓时资金变化  Start##############
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".($newdealprice*$quantity)." where user_id = ".$solderID);
											$restModel->query('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","卖出增加可用余额","'.($newdealprice*$quantity).'","'.date("Y-m-d H:i:s").'")');
											//将交易额结算给卖家############减仓时资金变化  Over##############
		
											$operate_fee = sprintf("%.4f",$newdealprice*($para[0]["operate"]/2));//运营手续费
											$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
											$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费
											
											if($pre1[0]["type"]==2){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
												
											
											}elseif($pre1[0]["type"]==3){
												
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
		
												$j0 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j0){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j0[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j0[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
												}
		
												
											
											}elseif($pre1[0]["type"]==4){
												
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s")."')");
		
												$j1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j1){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j1[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j1[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
												}
		
												$j2 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j1[0]["tuser_code"]."'");
												if($j2){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j2[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j2[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
												}
												
											
											}
										}
	
										//买家处理
										D("Rest")->querysql("insert into `jjs_shares`(user_id,g_code,cate,quantity,price,ctime) values('".$_SESSION["user_id"]."','".$g_code."','0','1','".$newdealprice."','".date("Y-m-d H:i:s")."')");
										$pre2 = $restModel->query("select tuser_code from `jjs_user` where id = ".$_SESSION["user_id"]);
										$pre3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre2[0]["tuser_code"]."'");
										//将交易额结算结余部分返还给买家############持仓时资金变化  Start##############
										$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".($price-$newdealprice)." where user_id = ".$_SESSION["user_id"]);
										$restModel->query('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18014","交易结算返还可用余额","'.($price-$newdealprice).'","'.date("Y-m-d H:i:s").'")');
										//将交易额结算结余部分返还给买家############持仓时资金变化  Over##############
	
										$operate_fee = sprintf("%.4f",$newdealprice*($para[0]["operate"]/2));//运营手续费
										$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
										$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费
										
										if($pre3[0]["type"]==2){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
										}elseif($pre3[0]["type"]==3){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
	
											$j3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
											if($j3){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j3[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
											}
	
											
										}elseif($pre3[0]["type"]==4){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s")."')");
	
											$j4 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
											if($j4){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j4[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j4[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
											}
	
											$j5 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j4[0]["tuser_code"]."'");
											if($j5){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j5[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j5[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
											}
											
										}
													
									
									
									
							}
							
							//同时写入持仓记录:已成
							$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,status,ctime) values('".$_SESSION["user_id"]."','".$g_code."','".$type."','".$quantity."','".$price."','1','".date("Y-m-d H:i:s",$time)."')");
							//将总交易额移至申购账户############持仓时资金变化  Start##############
							$restModel->querysql("update `jjs_user_finance` set bond = bond - ".$total." where user_id = ".$_SESSION["user_id"]);
							$restModel->query('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18011","持仓保证金","-'.$total.'","'.date("Y-m-d H:i:s").'")');
							
							
						//将总交易额移至申购账户############持仓时资金变化  Over##############
							//撮合流程 交易原则：价格&时间优先#####################Over#######//by enry at170111
							returnJson('200','操作成功');
						}
						else //队列不足
						{
							foreach($sqsrs as $row){
								
									
										
										
										$sqsdate = file_get_contents('http://apistore.51daniu.cn:1218/?charset=utf-8&name='.$g_code."-S-".$row["price"]."-".$row["user_id"]."-".strtotime($row["ctime"]).'&opt=get&auth=adminchen5188jjs');
										if($sqsdate == 'HTTPSQS_GET_END'){
											break;
										}else{
											$quantity_sqs--;
											$sqsdateArr = explode("-",$sqsdate);
											//最新成交价公式
											$solderID = $sqsdateArr[3];//卖家UID
											$solderPrice = $sqsdateArr[2];//平仓价
											if($price <= $pre_price) $newdealprice = $price;
											if($row["price"] >= $pre_price) $newdealprice = $row["price"];
											if($row["price"] < $pre_price && $pre_price < $price) $newdealprice = $pre_price;
											//卖家处理
											D("Rest")->querysql("insert into `jjs_shares`(user_id,g_code,cate,quantity,price,ctime) values('".$solderID."','".$g_code."','1','1','".$newdealprice."','".date("Y-m-d H:i:s")."')");
											$pre = $restModel->query("select tuser_code from `jjs_user` where id = ".$solderID);
											$pre1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre[0]["tuser_code"]."'");
		
											$operate_fee = sprintf("%.4f",$newdealprice*($para[0]["operate"]/2));//运营手续费
											$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
											$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费
											
											if($pre1[0]["type"]==2){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
											
											}elseif($pre1[0]["type"]==3){
												
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
		
												$j0 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j0){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j0[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j0[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
												}
		
												
											
											}elseif($pre1[0]["type"]==4){
												
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s")."')");
		
												$j1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j1){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j1[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j1[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
												}
		
												$j2 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j1[0]["tuser_code"]."'");
												if($j2){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j2[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j2[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
												}
												
											
											}
										}
	
										//买家处理
										
										//同时写入持仓记录:已成
										$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,status,ctime) values('".$_SESSION["user_id"]."','".$g_code."','".$type."','1','".$newdealprice."','1','".date("Y-m-d H:i:s",$time)."')");
										//将交易额结算结余部分返还给买家############持仓时资金变化  Start##############
										$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".($price-$newdealprice)." where user_id = ".$_SESSION["user_id"]);
										$restModel->query('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18014","交易结算返还可用余额","'.($price-$newdealprice).'","'.date("Y-m-d H:i:s").'")');
										//将交易额结算结余部分返还给买家############持仓时资金变化  Over##############
							
										D("Rest")->querysql("insert into `jjs_shares`(user_id,g_code,cate,quantity,price,ctime) values('".$_SESSION["user_id"]."','".$g_code."','0','1','".$newdealprice."','".date("Y-m-d H:i:s")."')");
										$pre2 = $restModel->query("select tuser_code from `jjs_user` where id = ".$_SESSION["user_id"]);
										$pre3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre2[0]["tuser_code"]."'");
	
										$operate_fee = sprintf("%.4f",$newdealprice*($para[0]["operate"]/2));//运营手续费
										$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
										$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费
										
										if($pre3[0]["type"]==2){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
										}elseif($pre3[0]["type"]==3){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
	
											$j3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
											if($j3){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j3[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
											}
	
											
										}elseif($pre3[0]["type"]==4){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s")."')");
	
											$j4 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
											if($j4){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j4[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j4[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s")."')");
											}
	
											$j5 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j4[0]["tuser_code"]."'");
											if($j5){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j5[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j5[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s")."')");
											}
											
										}
													
									
									
									
							}
							
							//同时写入持仓记录:已报
							$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,status,ctime) values('".$_SESSION["user_id"]."','".$g_code."','".$type."','".($quantity-count($sqsrs))."','".$price."','0','".date("Y-m-d H:i:s",$time)."')");
							
						}
							
						$quantity_more = $quantity-$quantity_sqs;
						//写入持仓表
						if($quantity_more>0)
						{
							$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,ctime) values('".$_SESSION["user_id"]."','".$g_code."','".$type."','".$quantity_more."','".$price."','".date("Y-m-d H:i:s",$time)."')");
						}
						//撮合流程 交易原则：价格&时间优先#####################Over#######//by enry at170110
						//$this->contract($g_code,$quantity,$price,0);
						returnJson('200','委托成功');

					}else{

						returnJson("500","可用余额不足，请充值");

					}

				}else{
					returnJson("500","交易密码错误");
				}
			}else{

				returnJson("500","委托价格异常");

			}
		}else{
			returnJson("500","数量已超出可买数量");
		}
		
	}

	//交易卖出
	public function sold()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$restModel=D("Rest");
		$g_code = trim(getgpc("g_code"));
	    $quantity = intval(getgpc('quantity'));//委托数量
	    $price = getgpc('price');//委托价
	    $tpassword = md5(getgpc("tpassword"));
		
		//商品信息
		$goods = D("Rest")->query("select user_id,code,title,price,up,down,num,holdnum,surplus_num,ctime from `jjs_goods_release` where code = '".$g_code."'");
		$nowtime = time();
		$opentime = strtotime(date("Y-m-d 04:00:00",time()));
		$closetime = strtotime(date("Y-m-d 21:00:00",time()));
		if($nowtime < $opentime){
			returnJson("500","今日暂未开盘");
		}elseif($nowtime > $closetime){
			returnJson("500","今日已收盘");
		}
		
		$coin = D("Rest")->query("select coin from `jjs_user_finance` where user_id = ".$_SESSION["user_id"]);
		$entrust = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and status = 0 and cate = 1 and is_del = 0");//委托卖出但未成交且未撤销的
		$dealnum = $coin[0]["coin"] - $entrust[0]["quantity"];//可卖数量
		
		//判断所传数量是否正确
		if($quantity <= $dealnum)
		{
			//判断所传价格是否介于跌停和涨停价之间
			if($goods[0]["down"]<$price && $price<$goods[0]["up"]){

				$user_tpassword = $restModel->query("select tpassword from `jjs_user` where id = ".$_SESSION["user_id"]);

				if($tpassword == $user_tpassword[0]["tpassword"]){//判断交易密码是否正确
					//先将持有减少
					//$restModel->querysql("update `jjs_user_finance` set coin = coin - ".$quantity." where user_id = ".$_SESSION["user_id"]);	
					$this->contract($g_code,$quantity,$price,1);
					returnJson('200','委托成功');

				}else{
					returnJson("500","交易密码错误");
				}
			}else{

				returnJson("500","委托价格异常");

			}
		}else{
			returnJson("500","数量已超出可卖数量");
		}
		

	}
	//交易处理
	/*final static private function contract($g_code,$quantity,$price,$type)//买入 0
	{
		
		//取队列
		$res = file

		//
		if($quantity_sqs){

			
		}

	}*/


	//创建商品价格流水表
	private function create_price()
	{
		$sql  = "CREATE TABLE `jjs_price_".date("Ymd")."` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`g_code` varchar(200) DEFAULT NULL COMMENT '商品代号',
			`price` decimal(11,2) DEFAULT NULL COMMENT '最新价格（即最新成交价）',
			`num` int(11) DEFAULT 0 COMMENT '以此价格成交的数量',
			`ctime` datetime DEFAULT NULL COMMENT '更新时间',
			PRIMARY KEY (`id`)
		)ENGINE=InnoDB DEFAULT CHARSET=utf8;";

		if(D('Rest')->querysql($sql))
		{
			//returnJson('200',"创建成功");
		}
		
	}

	//创建交易记录流水表
	private function create_sqs()
	{
		$sql  = "CREATE TABLE `jjs_sqs_".date("Ymd")."` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`s_key` varchar(200) DEFAULT NULL,
			`s_value` varchar(200) DEFAULT NULL,
			`ctime` datetime DEFAULT NULL COMMENT '更新时间',
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

		if(D('Rest')->querysql($sql))
		{
			//returnJson('200',"创建成功");
		}
		
	}

	//发售交易列表
	public function dealist()
	{
		$goods = D("Rest")->query("select id,user_id,code,price,title,num,surplus_num from `jjs_goods_release` where release_status = 1");
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				$highestprice = array();
				$lowestprice = array();
				$newprice = array();
				$totalprice = array();
				$num = array();
				foreach($pricetables as $k=>$v){
					//最高价、最低价
					$res = D("Rest")->query("select max(price) as highestprice,min(price) as lowestprice from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($res[0]["highestprice"]) $highestprice[] = $res[0]["highestprice"];
					if($res[0]["lowestprice"]) $lowestprice[] = $res[0]["lowestprice"];
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc limit 1");
					if($deal[0]["price"]) $newprice[] = $deal[0]["price"];
					
					//价格统计
					$total = D("Rest")->query("select sum(price) as totalprice,count(1) as count from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($total[0]["totalprice"]){ 
						$totalprice["totalprice"] += $total[0]["totalprice"];
						$totalprice["count"] += $total[0]["count"];
					}

					//成交量统计
					$totalnum = D("Rest")->query("select sum(num) as dealnum from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($totalnum[0]["dealnum"]){ 
						$dealnum += $totalnum[0]["dealnum"];
					}

				}
				$highestprice[] = $vo["price"];
				$lowestprice[] = $vo["price"];
				rsort($highestprice);
				sort($lowestprice);

				$opentime = date("Y-m-d 04:00:00",time());
				$closetime = date("Y-m-d 21:00:00",time()-24*3600);
				$openprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time())."` where g_code = '".$vo["code"]."' and ctime > '".$opentime."' order by ctime asc limit 1");
				$closeprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time()-24*3600)."` where g_code = '".$vo["code"]."' and ctime < '".$closetime."' order by ctime desc limit 1");

				
				if($newprice){ 
					$range = end($newprice)-$vo["price"];
					$rangerate = sprintf("%.2f",($range/$vo["price"])*100)."%";
					$goods[$key]["newprice"] = end($newprice); //最新价
					$goods[$key]["range"] = $range; //涨跌
					$goods[$key]["rangerate"] = $rangerate; //幅度
				}else{ 
					$goods[$key]["newprice"] = $vo["price"];
					$goods[$key]["range"] = 0; //涨跌
					$goods[$key]["rangerate"] = 0; //幅度
				}

				//是否持有
				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}

				//是否加入自选
				$is_optional = D("Rest")->query("select * from `jjs_optional` where g_code = '".$vo["code"]."' and user_id = ".$_SESSION["user_id"]);
				if($is_optional){
					
					if($is_optional[0]["is_del"]==0) $goods[$key]["is_del"] = "取消自选"; else $goods[$key]["is_del"] = "加入自选";//是否加入自选
				}else{
					$goods[$key]["is_del"] = "加入自选";
				}

				//限量统计
				$coin = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and status = 0 and cate = 1 and is_del = 0");
				$surplus = $vo["surplus_num"]-$coin[0]["quantity"];//可卖数量
				$goods[$key]["surplus"] = $surplus;

				if($highestprice) $goods[$key]["highestprice"] = $highestprice[0]; else $goods[$key]["highestprice"] = $vo["price"];//最高价
				if($lowestprice) $goods[$key]["lowestprice"] = $lowestprice[0]; else $goods[$key]["lowestprice"] = $vo["price"];//最低价
				if($totalprice) $goods[$key]["avarageprice"] = round($totalprice["totalprice"]/$totalprice["count"],2); else $goods[$key]["avarageprice"] = $vo["price"];//均价
				if($dealnum) $goods[$key]["dealnum"] = $dealnum; else $goods[$key]["dealnum"] = 0;//成交量

				if($openprice[0]["price"] && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif($openprice[0]["price"] && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					if($now[0]["t_price"]) $goods[$key]["closeprice"] = $now[0]["t_price"];else $goods[$key]["closeprice"] = $vo["price"];

				}elseif(empty($openprice[0]["price"]) && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $closeprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif(empty($openprice[0]["price"]) && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					
					if($now[0]["t_price"]) {
						$goods[$key]["openprice"] = $now[0]["t_price"]; //开盘价
						$goods[$key]["closeprice"] = $now[0]["t_price"];
					}else{
						$goods[$key]["openprice"] = $vo["price"]; //开盘价
						$goods[$key]["closeprice"] = $vo["price"];//昨日收盘价
					}

				}
				
			}
		}


		
		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		

	}

	//入场登记详情
	public function release_detail()
	{
		$g_code = trim(getgpc("g_code"));
		$goods = D("Rest")->query("select id,user_id,code,price,title,num,surplus_num from `jjs_goods_release` where code = '".$g_code."'");
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				foreach($pricetables as $k=>$v){
					//最高价、最低价
					$res = D("Rest")->query("select max(price) as highestprice,min(price) as lowestprice from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($res[0]["highestprice"]) $highestprice[] = $res[0]["highestprice"];
					if($res[0]["lowestprice"]) $lowestprice[] = $res[0]["lowestprice"];
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc limit 1");
					if($deal[0]["price"]) $newprice[] = $deal[0]["price"];
					
					//价格统计
					$total = D("Rest")->query("select sum(price) as totalprice,count(1) as count from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($total[0]["totalprice"]){ 
						$totalprice["totalprice"] += $total[0]["totalprice"];
						$totalprice["count"] += $total[0]["count"];
					}

					//成交量统计
					$totalnum = D("Rest")->query("select sum(num) as dealnum from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($totalnum[0]["dealnum"]){ 
						$dealnum += $totalnum[0]["dealnum"];
					}
				}
				$highestprice[] = $vo["price"];
				$lowestprice[] = $vo["price"];
				rsort($highestprice);
				sort($lowestprice);

				$opentime = date("Y-m-d 04:00:00",time());
				$closetime = date("Y-m-d 21:00:00",time()-24*3600);
				$openprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time())."` where g_code = '".$vo["code"]."' and ctime > '".$opentime."' order by ctime asc limit 1");
				$closeprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time()-24*3600)."` where g_code = '".$vo["code"]."' and ctime < '".$closetime."' order by ctime desc limit 1");
				
				//市价与昨日收盘的之间的关系
				if($closeprice[0]["price"]){
					$mcloseprice = $vo["price"]-$closeprice[0]["price"];
					$mcloserate = sprintf("%.2f",($mcloseprice/$closeprice[0]["price"])*100)."%";
				}else{
					$mcloseprice = "-";
					$mcloserate = "-";
				}
				$goods[$key]["mcloseprice"] = $mcloseprice; //mapp数据
				$goods[$key]["mcloserate"] = $mcloserate; //mapp数据
				
				if($newprice){ 
					$range = end($newprice)-$vo["price"];
					$rangerate = sprintf("%.2f",($range/$vo["price"])*100)."%";
					$goods[$key]["newprice"] = end($newprice); //最新价
					$goods[$key]["range"] = $range; //涨跌
					$goods[$key]["rangerate"] = $rangerate; //幅度
					
				}else{ 
					$goods[$key]["newprice"] = $vo["price"];
					$goods[$key]["range"] = 0; //涨跌
					$goods[$key]["rangerate"] = 0; //幅度
				}

				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}

				//是否持有
				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}
				//是否加入自选
				$is_optional = D("Rest")->query("select * from `jjs_optional` where g_code = '".$vo["code"]."' and user_id = ".$_SESSION["user_id"]);
				//总量、总额
				$total = $vo["num"]*$vo["price"];
				$b = 1000;
				$c = 10000;
				$d = 100000000;
				 
				if($total>$b){
					if ($total<$c) {
						$total = floor($total/$b).'千';
					}elseif($total<$d) {
						$total = (floor(($total/$c)*100)/100).'万';
					}else{
						$total = (floor(($total/$d)*100)/100).'亿';
					}
				}

				//现量统计
				$coin = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and status = 0 and cate = 1 and is_del = 0");
				$surplus = $vo["surplus_num"]-$coin[0]["quantity"];//现量
				$goods[$key]["surplus"] = $surplus;
				$goods[$key]["total"] = $total;//总额
				if($highestprice) $goods[$key]["highestprice"] = $highestprice[0]; else $goods[$key]["highestprice"] = $vo["price"];//最高价
				if($lowestprice) $goods[$key]["lowestprice"] = $lowestprice[0]; else $goods[$key]["lowestprice"] = $vo["price"];//最低价
				if($totalprice) $goods[$key]["avarageprice"] = round($totalprice["totalprice"]/$totalprice["count"],2); else $goods[$key]["avarageprice"] = $vo["price"];//均价
				if($dealnum) $goods[$key]["dealnum"] = $dealnum; else $goods[$key]["dealnum"] = 0;//成交量
				if($is_optional){
					if($is_optional[0]["is_del"]==0) $goods[$key]["is_del"] = "取消自选"; else $goods[$key]["is_del"] = "加入自选";//是否加入自选
				}else{
					$goods[$key]["is_del"] = "加入自选";
				}
				
				if($openprice[0]["price"] && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif($openprice[0]["price"] && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					if($now[0]["t_price"]) $goods[$key]["closeprice"] = $now[0]["t_price"];else $goods[$key]["closeprice"] = $vo["price"];

				}elseif(empty($openprice[0]["price"]) && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $closeprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif(empty($openprice[0]["price"]) && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					
					if($now[0]["t_price"]) {
						$goods[$key]["openprice"] = $now[0]["t_price"]; //开盘价
						$goods[$key]["closeprice"] = $now[0]["t_price"];
					}else{
						$goods[$key]["openprice"] = $vo["price"]; //开盘价
						$goods[$key]["closeprice"] = $vo["price"];//昨日收盘价
					}

				}
				
			}
			//成绩记录
			$deal = D("Rest")->query("select sum(quantity) as quantity,t_price,deal_time from `jjs_contract` where status>0 and g_code = '".$g_code."' group by deal_time,cate");
			$goods[$key]["deal"] = $deal;
		}


		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
	}

	//加入自选
	public function add_optional()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$is_optional = D("Rest")->query("select * from `jjs_optional` where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
		if($is_optional){
			if($is_optional[0]["is_del"] == 0){
				returnJson("500","该商品已在自选列表");
			}elseif($is_optional[0]["is_del"] == 1){
				$res = D("Rest")->querysql("update `jjs_optional` set is_del = 0 where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
			}
		}else{
			$res = D("Rest")->querysql("insert into `jjs_optional`(g_code,user_id,ctime) values('".$g_code."','".$_SESSION["user_id"]."','".time()."')");
		}
		if($res){
			returnJson("200","加入成功");
		}else{
			returnJson("500","加入失败");
		}
		
	}

	//取消自选
	public function cancle_optional()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$res = D("Rest")->querysql("update `jjs_optional` set is_del = 1 where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
		if($res){
			returnJson("200","取消成功");
		}else{
			returnJson("500","取消失败");
		}
	}

	//删除自选
	public function del_optional()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$res = D("Rest")->querysql("delete from `jjs_optional` where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
		if($res){
			returnJson("200","删除成功");
		}else{
			returnJson("500","删除失败");
		}
	}

	//自选列表
	public function optionalist()
	{
		$goods = D("Rest")->query("select R.id,R.user_id,R.price,R.title,R.code,R.num,R.surplus_num from `jjs_goods_release` R,`jjs_optional` O where O.g_code = R.code and O.is_del = 0");
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				$highestprice = array();
				$lowestprice = array();
				$newprice = array();
				$totalprice = array();
				$num = array();
				foreach($pricetables as $k=>$v){
					//最高价、最低价
					$res = D("Rest")->query("select max(price) as highestprice,min(price) as lowestprice from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($res[0]["highestprice"]) $highestprice[] = $res[0]["highestprice"];
					if($res[0]["lowestprice"]) $lowestprice[] = $res[0]["lowestprice"];
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc limit 1");
					if($deal[0]["price"]) $newprice[] = $deal[0]["price"];
					
					//价格统计
					$total = D("Rest")->query("select sum(price) as totalprice,count(1) as count from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($total[0]["totalprice"]){ 
						$totalprice["totalprice"] += $total[0]["totalprice"];
						$totalprice["count"] += $total[0]["count"];
					}

					//成交量统计
					$totalnum = D("Rest")->query("select sum(num) as dealnum from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($totalnum[0]["dealnum"]){ 
						$dealnum += $totalnum[0]["dealnum"];
					}

				}
				$highestprice[] = $vo["price"];
				$lowestprice[] = $vo["price"];
				rsort($highestprice);
				sort($lowestprice);

				$opentime = date("Y-m-d 04:00:00",time());
				$closetime = date("Y-m-d 21:00:00",time()-24*3600);
				$openprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time())."` where g_code = '".$vo["code"]."' and ctime > '".$opentime."' order by ctime asc limit 1");
				$closeprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time()-24*3600)."` where g_code = '".$vo["code"]."' and ctime < '".$closetime."' order by ctime desc limit 1");

				
				if($newprice){ 
					$range = end($newprice)-$vo["price"];
					$rangerate = sprintf("%.2f",($range/$vo["price"])*100)."%";
					$goods[$key]["newprice"] = end($newprice); //最新价
					$goods[$key]["range"] = $range; //涨跌
					$goods[$key]["rangerate"] = $rangerate; //幅度
				}else{ 
					$goods[$key]["newprice"] = $vo["price"];
					$goods[$key]["range"] = 0; //涨跌
					$goods[$key]["rangerate"] = 0; //幅度
				}

				
				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}

				//现量统计
				$coin = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and status = 0 and cate = 1 and is_del = 0");
				$surplus = $vo["surplus_num"]-$coin[0]["quantity"];//现量
				$goods[$key]["surplus"] = $surplus;
				
				if($highestprice) $goods[$key]["highestprice"] = $highestprice[0]; else $goods[$key]["highestprice"] = $vo["price"];//最高价
				if($lowestprice) $goods[$key]["lowestprice"] = $lowestprice[0]; else $goods[$key]["lowestprice"] = $vo["price"];//最低价
				if($totalprice) $goods[$key]["avarageprice"] = round($totalprice["totalprice"]/$totalprice["count"],2); else $goods[$key]["avarageprice"] = $vo["price"];//均价
				if($dealnum) $goods[$key]["dealnum"] = $dealnum; else $goods[$key]["dealnum"] = 0;//成交量
				
				if($openprice[0]["price"] && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif($openprice[0]["price"] && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					if($now[0]["t_price"]) $goods[$key]["closeprice"] = $now[0]["t_price"];else $goods[$key]["closeprice"] = $vo["price"];

				}elseif(empty($openprice[0]["price"]) && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $closeprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif(empty($openprice[0]["price"]) && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					
					if($now[0]["t_price"]) {
						$goods[$key]["openprice"] = $now[0]["t_price"]; //开盘价
						$goods[$key]["closeprice"] = $now[0]["t_price"];
					}else{
						$goods[$key]["openprice"] = $vo["price"]; //开盘价
						$goods[$key]["closeprice"] = $vo["price"];//昨日收盘价
					}

				}
			}
		}
		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		
		

	}

	
	//行情分时图数据
	public function quotation()
	{
		
		$g_code = trim(getgpc("g_code"));
		$id = intval(getgpc("id"));
		$direction = trim(getgpc("direction"));
		$range = trim(getgpc("range"));
		if($direction && $id){
			if($direction == "left"){ 

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id<".$id." order by id desc limit 1");
				if(empty($goods)) returnJson("500","没有啦");

			}elseif($direction == "right"){

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id>".$id." order by id asc limit 1");
				if(empty($goods)) returnJson("500","没有啦");
			}
		}else{
			$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where code = '".$g_code."'");
			
		}
		
		if($range == "日"){

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where to_days(`deal_time`) = to_days(now()) and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "周"){

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where YEARWEEK(date_format(`deal_time`,'%Y-%m-%d')) = YEARWEEK(now()) and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "月"){

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where PERIOD_DIFF( date_format( now( ) , '%Y%m' ) , date_format(deal_time, '%Y%m')) =0 and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "季"){
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where QUARTER(`deal_time`)=QUARTER(now()) and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");
		}elseif($range == "1"){
			
			$ltime = date("Y-m-d H:i:s",time()-60);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "3"){

			$ltime = date("Y-m-d H:i:s",time()-60*3);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "5"){

			$ltime = date("Y-m-d H:i:s",time()-60*5);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "15"){

			$ltime = date("Y-m-d H:i:s",time()-60*15);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "30"){

			$ltime = date("Y-m-d H:i:s",time()-60*30);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "60"){

			$ltime = date("Y-m-d H:i:s",time()-60*60);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "120"){

			$ltime = date("Y-m-d H:i:s",time()-60*120);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "240"){

			$ltime = date("Y-m-d H:i:s",time()-60*240);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}else{

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");
			unset($r[0]);
		}

		
		foreach($r as $k=>$v){
			$r1 = D("Rest")->query("select t_price from `jjs_contract` where deal_time like '%".$v["newtime"]."%' and g_code = '".$goods[0]["code"]."' group by status order by deal_time asc");
			$r[$k]["openprice"] = reset($r1)["t_price"];
			$r[$k]["closeprice"] = end($r1)["t_price"];
		}
		
		$list = array();
		foreach($r as $key=>$vo){
			$list[$key]["deal_time"] = strtotime($vo["newtime"])."000";
			$list[$key]["openprice"] = $vo["openprice"];
			$list[$key]["highestprice"] = $vo["highestprice"];
			$list[$key]["lowestprice"] = $vo["lowestprice"];
			$list[$key]["closeprice"] = $vo["closeprice"];
		}
			
		foreach($list as $k=>$v){
			$arr = array();
			$arr[] = $v["deal_time"];
			$arr[] = $v["openprice"];
			$arr[] = $v["highestprice"];
			$arr[] = $v["lowestprice"];
			$arr[] = $v["closeprice"];
			$result[] = $arr;
		}
		returnJson("200","",array("id"=>$goods[0]["id"],"g_code"=>$goods[0]["code"],"k_data"=>$result));
		

	}

	

	//持仓记录
	public function holdlist()
	{
		$goods = D("Rest")->query("select C.cate,C.ctime,R.id,R.code,R.title,R.price as mprice,R.user_id,R.num,R.surplus_num,sum(quantity) as quantity,C.status,C.t_price,C.is_del,C.status from `jjs_contract` C,`jjs_goods_release` R where C.g_code = R.code and C.user_id = ".$_SESSION["user_id"]." group by C.ctime,C.cate,C.g_code order by C.ctime desc");
		$para = D("Rest")->query("select * from	`jjs_deal_setting`");
		foreach($goods as $key=>$vo){
			
			if($vo["status"]>0){
				//最新价
				$newprice = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status > 0 order by deal_time desc limit 1");
				//交易手续费
				$fee = sprintf("%.4f",($vo["quantity"]*$vo["t_price"]*($para[0]["fee"]/2)));
				//成本
				$cost = sprintf("%.4f",($vo["quantity"]*$vo["t_price"]+$fee)/$vo["quantity"]);
				//盈亏
				$shares =(sprintf("%.4f", (($newprice[0]["t_price"]*$vo["quantity"])-($vo["quantity"]*$vo["t_price"]+$fee))/($vo["quantity"]*$vo["t_price"]+$fee))*100)."%";
			}else{
				//成本
				$cost = '-';
				//盈亏
				$shares = '-';
			}

			//可用
			$available = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and user_id = ".$_SESSION["user_id"]." and ctime = '".$vo["ctime"]."' and status > 0 and cate = ".$vo["cate"]);
			if($newprice[0]["t_price"])$goods[$key]["newprice"] = $newprice[0]["t_price"]; else $goods[$key]["newprice"] = $goods[0]["mprice"];//最新价(现价)
			$goods[$key]["shares"] = $shares; //盈亏
			$goods[$key]["cost"] = $cost; //成本
			if($available[0]["quantity"])$goods[$key]["available"] = $available[0]["quantity"];else  $goods[$key]["available"] = 0;//可用
			
		}

		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
	}

	//交易撤单
	public function cancle_deal()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$ctime = trim(getgpc("ctime"));
		$cate = intval(getgpc("cate"));
		$r = D("Rest")->query("select sum(price) as price from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");
		if($r){
			$res = D("Rest")->querysql("update `jjs_contract` set is_del = 1 where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");
			D("Rest")->querysql("insert into `jjs_detail` (user_id,cate,remark,amount,ctime) values('".$_SESSION["user_id"]."','2552','交易撤单','".$r[0]["price"]."','".date("Y-m-d H:i:s")."')");
			if($res){
				returnJson("200","撤单成功");
			}else{
				returnJson("500","撤单失败");
			}
		}else{
			returnJson("500","撤单失败");
		}
		
	}

	//持仓记录查询
	public function holdetail()
	{
		$ctime = trim(getgpc("ctime"));
		$cate = intval(getgpc("cate"));
		$g_code = trim(getgpc("g_code"));
		$para = D("Rest")->query("select * from	`jjs_deal_setting`");
		if($ctime && $g_code){
			$goods = D("Rest")->query("select C.cate,C.price,C.ctime,C.deal_time,R.id,R.code,R.title,R.user_id,R.num,sum(quantity) as quantity,C.status,C.t_price,C.is_del,R.price as mprice from `jjs_contract` C,`jjs_goods_release` R where C.g_code = R.code and C.user_id = ".$_SESSION["user_id"]." and C.ctime = '".$ctime."' and C.cate = ".$cate." and C.g_code = '".$g_code."'");
			foreach($goods as $key=>$vo){
				//echo $vo["quantity"]*$vo["t_price"]*($para[0]["fee"]/2);
				//交易手续费
				$fee = sprintf("%.4f",($vo["quantity"]*$vo["t_price"]*($para[0]["fee"]/2)));
				$goods[$key]["fee"] = $fee;

				//方向
				if($vo["cate"]==0){
					$goods[$key]["cate"] = "买入";
					$goods[$key]["total"] = $vo["quantity"]*$vo["t_price"]+$fee; //费用合计
				}else{
					$goods[$key]["cate"] = "卖出";
					$goods[$key]["total"] = $vo["quantity"]*$vo["t_price"]-$fee; //费用合计
				}

				//交易额
				$goods[$key]["turnover"] = $vo["quantity"]*$vo["t_price"];

				//操作类型
				if($vo["is_del"]==0){
					if($vo["cate"]==0){
						$goods[$key]["type"] = "直接买入"; //方向
					}else{
						$goods[$key]["type"] = "直接卖出"; //方向
					}
				}else{
					$goods[$key]["type"] = "委托撤单"; //方向
				}
				
				if(substr($vo["deal_time"],0,1)==0) $goods[$key]["deal_time"] = "--"; //成交时间
				if(substr($vo["t_price"],0,1)==0) $goods[$key]["t_price"] = "--"; //成交价格

			}
		}

		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		
	}

	//删除交易
	public function del_deal()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$ctime = trim(getgpc("ctime"));
		$cate = intval(getgpc("cate"));
		$r = D("Rest")->query("select * from `jjs_contract`  where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");
		if($r){
			$res = D("Rest")->querysql("delete from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");

			if($res){
				returnJson("200","删除成功");
			}else{
				returnJson("500","删除失败");
			}
		}else{
			returnJson("500","删除失败");
		}
	}

	//K线图
	public function K_data()
	{
		$g_code = trim(getgpc("g_code"));
		$id = intval(getgpc("id"));
		$direction = trim(getgpc("direction"));
		$range = trim(getgpc("range"));
		if($direction && $id){
			if($direction == "left"){ 

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id<".$id." order by id desc limit 1");
				if(empty($goods)) returnJson("500","没有啦");

			}elseif($direction == "right"){

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id>".$id." order by id asc limit 1");
				if(empty($goods)) returnJson("500","没有啦");
			}
		}else{
			$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where code = '".$g_code."'");
			
		}
		
		if($range == "日"){

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where to_days(`deal_time`) = to_days(now()) and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "周"){

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where YEARWEEK(date_format(`deal_time`,'%Y-%m-%d')) = YEARWEEK(now()) and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "月"){

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where PERIOD_DIFF( date_format( now( ) , '%Y%m' ) , date_format(deal_time, '%Y%m')) =0 and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "季"){
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where QUARTER(`deal_time`)=QUARTER(now()) and g_code = '".$goods[0]["code"]."' group by deal_time");
		}elseif($range == "1"){
			
			$ltime = date("Y-m-d H:i:s",time()-60);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "3"){

			$ltime = date("Y-m-d H:i:s",time()-60*3);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "5"){

			$ltime = date("Y-m-d H:i:s",time()-60*5);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "15"){

			$ltime = date("Y-m-d H:i:s",time()-60*15);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "30"){

			$ltime = date("Y-m-d H:i:s",time()-60*30);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "60"){

			$ltime = date("Y-m-d H:i:s",time()-60*60);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "120"){

			$ltime = date("Y-m-d H:i:s",time()-60*120);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "240"){

			$ltime = date("Y-m-d H:i:s",time()-60*240);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}else{

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where g_code = '".$goods[0]["code"]."' group by deal_time");
			unset($r[0]);
		}
		
			
		foreach($r as $k=>$v){
			$arr = array();
			$arr[] = strtotime($v["deal_time"])."000";
			$arr[] = $v["t_price"];
			$result[] = $arr;
		}
		returnJson("200","",array("id"=>$goods[0]["id"],"g_code"=>$goods[0]["code"],"k_data"=>$result));
	}

	public function sqs()
	{
		$res = D("Rest")->query("select * from `jjs_contract` where status = -1");
		foreach($res as $k=>$v){
			for($i=1;$i<=$v["quantity"];$i++){
				$name = $v["g_code"]."-".($v["cate"]==0)?"B":"S"."-".$v["price"]."-".strtotime($v["ctime"]);
				$data = $v["g_code"]."-".($v["cate"]==0)?"B":"S"."-".$v["price"]."-".$v["user_id"].rand(100000000000000000,999999999999999999);
				
				
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$name."&opt=put&data=".$data."&auth=adminchen5188jjs");

				
			}
			D("Rest")->querysql("update `jjs_contract` set status = 0 where id = ".$v["id"]);
		}
	}

	//收盘
	public function close()
	{
		$res = D("Rest")->query("select * from `jjs_contract` where status <=0 and ctime like '%".date("Y-m-d",time())."%'");
		foreach($res as $k=>$v){
			$name = $v["g_code"]."-".($v["cate"]==0?"B":"S")."-".$v["price"].$v["user_id"].strtotime($v["ctime"]);
			$data[] = file_get_contents("http://apistore.51daniu.cn:1218/?name=".$name."&opt=get&auth=adminchen5188jjs");

			print_r($data);








			/*D("Rest")->querysql("update `jjs_contract` set is_del = 1 where id = ".$v["id"]);
			D("Rest")->querysql("update `jjs_user_finance` set bond = bond-'".($v["price"]*$v["quantity"])."' where user_id = ".$v["user_id"]);
			D("Rest")->querysql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$v["user_id"]."','5489','系统撤单','".($v["price"]*$v["quantity"])."','".date("Y-m-d H:i:s")."')");
			if($v["cate"] == 0)
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$v["g_code"]."-B-".$v["price"]."-".strtotime($v["ctime"])."-".$v["user_id"]."&opt=get&auth=adminchen5188jjs");
			elseif($v["cate"] == 1)
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$v["g_code"]."-S-".$v["price"]."-".strtotime($v["ctime"])."-".$v["user_id"]."&opt=get&auth=adminchen5188jjs");*/
		}
	}

	//开盘
	public function open()
	{
		$userhold = 0;
		$res = D("Rest")->query("select * from `jjs_goods_realease`");
		foreach($res as $k=>$v){
			$newprice = D("Rest")->query("select price from `jjs_shares` where g_code = '".$v["g_code"]."' order by ctime desc limit 1");

			$r = D("Rest")->query("select sum(num) as total from `jjs_user_gcode` where g_code = '".$v["g_code"]."'");
			$surplus = $v["num"]-$r[0]["total"];
			D("Rest")->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,status,ctime) values('".$v["user_id"]."','".$v["g_code"]."','1','".$surplus."','".$$newprice['price']."','-1','".date("Y-m-d H:i:s")."')");
			for($i=1;$i<=$surplus;$i++){
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$v["g_code"]."-S-".$v['price']."&opt=put&data=".$data."&auth=adminchen5188jjs");
			}
		}

		foreach($res as $k=>$v){
			
			D("Rest")->querysql("update `jjs_contract` set is_del = 1 where id = ".$v["id"]);
			D("Rest")->querysql("update `jjs_user_finance` set bond = bond-'".($v["price"]*$v["quantity"])."' where user_id = ".$v["user_id"]);
			D("Rest")->querysql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$v["user_id"]."','5489','系统撤单','".($v["price"]*$v["quantity"])."','".date("Y-m-d H:i:s")."')");
		}
	}

}
=======
<?php
defined('IN_PHPFRAME') or exit('No permission resources.');
pc_base::load_sys_class('BaseAction');

class trade extends BaseAction
{
	public function __construct()
	{
		//登录鉴权
	    /*
		$_SESSION['user_id'] = '695567515';*/
		parent::__construct();
		self::create_price();
		self::create_sqs();
	}

	

	//交易页面数据
	public function index()
	{
		$restModel = D("Rest");
		//商品信息
		$g_code = trim(getgpc("g_code"));
		$cate = trim(getgpc("cate"));
		$goods = $restModel->query("select user_id,code,title,price,up,down,num,holdnum,surplus_num from `jjs_goods_release` where code = '".$g_code."'");
		//手续费比例(百分比可在后台修改)
		$para = $restModel->query("select * from	`jjs_deal_setting`");
		$fee = (($para[0]["fee"]/2)*100)."%";
		$pricetables = $restModel->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				foreach($pricetables as $k=>$v){
					
					//最新价
					$deal = $restModel->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$g_code."' order by ctime desc limit 1");
					if($deal[0]["price"]) $price[] = $deal[0]["price"];
				}

				if(end($price)){
					//有交易记录
					$newprice = end($price);//最新价
				}else{
					//无交易记录
					$newprice = $vo["price"];//最新价
				}
				
				if($cate == "卖"){

					$allnum = $restModel->query("select num from `jjs_user_gcode` where user_id = ".$_SESSION["user_id"]." and g_code = ".$g_code);
					$entrust = $restModel->query("select sum(lave_quantity) as lave_quantity from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and status = 0 and cate = 1 and is_del = 0");//委托卖出但未成交且未撤销的
					$dealnum = $all[0]["num"] - $entrust[0]["lave_quantity"];//可卖数量
						
					//交易总额
					$total = $newprice*$dealnum;
				}else{//买
					$dealnum = $vo["holdnum"];
					//交易总额
					$total = $newprice*$vo["holdnum"]*(1+($para[0]["fee"]/2));

				}
				$goods[$key]["newprice"] = $newprice;//最新价
				$goods[$key]["dealnum"] = $dealnum;//可交易数量
				$goods[$key]["cate"] = $cate;//交易类型
				$goods[$key]["total"] = $total;//总额
				$goods[$key]["fee"] = $fee;//手续费
			}
		}
		
		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		
	}

	//交易买入
	public function buy()
	{
	    if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$restModel=D("Rest");
		$g_code = trim(getgpc("g_code"));
	    $quantity = intval(getgpc('quantity'));//委托数量
	    $price = getgpc('price');//委托价
	    $tpassword = md5(getgpc("tpassword"));
		$parameter = D("Rest")->query("select * from `jjs_deal_setting`");
		//商品信息
		$goods = D("Rest")->query("select user_id,code,title,price,up,down,num,holdnum,surplus_num,ctime from `jjs_goods_release` where code = '".$g_code."'");
		$nowtime = time();
		$opentime = strtotime(date("Y-m-d 08:00:00",time()));
		$closetime = strtotime(date("Y-m-d 17:00:00",time()));
		if($nowtime < $opentime){
			returnJson("500","今日暂未开盘");
		}elseif($nowtime > $closetime){
			returnJson("500","今日已收盘");
		}
		if($_SESSION["user_id"] == $goods[0]["user_id"]){
			returnJson("500","您是交易商，不能进行买入交易");
		}
		if($quantity<1){
			returnJson("500","委托数量不合法");
		}
		//判断所传数量是否正确
		if($quantity <= $goods[0]["holdnum"])
		{
			//判断所传价格是否介于跌停和涨停价之间
			if($goods[0]["down"]<$price && $price<$goods[0]["up"]){
				//交易总额=持仓总额+千分之二点五持仓手续费 by enry at170111
				$total = $quantity*$price*(1+0.0025);
				//用户帐户余额查询
				$account = $restModel->query("select * from `jjs_user_finance` where user_id = ".$_SESSION["user_id"]);
				$available = $account[0]["recharge"]+$account[0]["inamount"]+$account[0]["extendamount"]+$account[0]["tempamount"]-$account[0]["withdraw"]-$account[0]["bond"]-$account[0]["outamount"];
				
				$user_tpassword = $restModel->query("select tpassword from `jjs_user` where id = ".$_SESSION["user_id"]);

				if($tpassword == $user_tpassword[0]["tpassword"]){//判断交易密码是否正确
					if($total <= $available){
						//将总交易额移至申购账户############持仓时资金变化  Start##############
						$restModel->querysql("update `jjs_user_finance` set bond = bond + ".$total." where user_id = ".$_SESSION["user_id"]);
						$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18010","持仓可用余额","-'.$total.'","'.date("Y-m-d H:i:s").'")');
						$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18011","持仓保证金","'.$total.'","'.date("Y-m-d H:i:s").'")');
						
						//将总交易额移至申购账户############持仓时资金变化  Over##############
						
						//写入挂单  by jessie 170111
						//写入持仓记录:已报
						$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,lave_quantity,status,ctime) values('".$_SESSION["user_id"]."','".$g_code."','0','".$quantity."','".$price."','".$quantity."','0','".date("Y-m-d H:i:s",$nowtime)."')");
						$currentid = $restModel->query("select last_insert_id()");//获取当前的记录id
						for($i=1;$i<=$quantity;$i++){
							$name = $g_code."-B-".$price."-".$_SESSION["user_id"]."-".$currentid[0]["last_insert_id()"];
							$data = $g_code."-B-".$price."-".$_SESSION["user_id"]."-".$currentid[0]["last_insert_id()"]."-".rand(100000000000000000,999999999999999999);
							//写入队列
							file_get_contents("http://apistore.51daniu.cn:1218/?name=".$name."&opt=put&data=".$data."&auth=adminchen5188jjs");
						}
						
						
						//上一笔成交价格
						$prerecord = $restModel->query("select price from `jjs_shares` where g_code = '".$g_code."' order by ctime desc limit 1");
						if($prerecord[0]["price"])$pre_price = $prerecord[0]["price"];else $pre_price = $goods[0]["price"];
	
						//持仓撮合流程 交易原则：价格&时间优先#####################Start#######//by enry at170111
						$quantity_sqs = $quantity;
						$sqstime = time();
						$sqsrscount = $restModel->query("select sum(lave_quantity) as count from `jjs_contract` where cate=1 and ctime like '%".date("Y-m-d",$sqstime)."%' and g_code='".$g_code."' and status=0 and price<='".$price."' and lave_quantity > 0 order by ctime asc ");//获取本积分有效平仓总数
						
						$sqsrs = $restModel->query("select * from `jjs_contract` where cate=1 and ctime like '%".date("Y-m-d",$sqstime)."%' and g_code='".$g_code."' and status=0 and price<='".$price."' and lave_quantity > 0 order by ctime asc ");//获取本积分有效平仓记录
						if($sqsrscount[0]["count"]==0)//平仓记录为空
						{
							returnJson('200','操作成功');
						}
						
						if($quantity <= $sqsrscount[0]["count"])//队列充足
						{
							foreach($sqsrs as $row){
								if($quantity <= $row["lave_quantity"]){
									for($i=1;$i<=$quantity;$i++){
										$sqsdate = file_get_contents('http://apistore.51daniu.cn:1218/?charset=utf-8&name='.$g_code."-S-".$row["price"]."-".$row["user_id"]."-".$row["id"].'&opt=get&auth=adminchen5188jjs');
										if($sqsdate == 'HTTPSQS_GET_END'){
											break;
										}else{
											//$quantity_sqs--;
											$sqsdateArr = explode("-",$sqsdate);
											
											$contractid = $sqsdateArr[4];//委托记录id(合约ID)
											$solderID = $sqsdateArr[3];//卖家UID
											$solderPrice = $sqsdateArr[2];//平仓价

											//最新成交价公式
											if($price <= $pre_price) $newdealprice = $price;
											if($solderPrice >= $pre_price) $newdealprice = $solderPrice;
											if($solderPrice < $pre_price && $pre_price < $price) $newdealprice = $pre_price;
											
											/***卖家处理**********************  start *******************/

											//写入成交记录
											$restModel->querysql("insert into `jjs_shares`(contract_id,user_id,g_code,cate,quantity,price,ctime) values('".$contractid."','".$solderID."','".$g_code."','1','1','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");
											
											//写入价格流水表
											$restModel->querysql("insert into `jjs_price_".date("Ymd",time())."`(g_code,price,ctime) values('".$g_code."','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");

											//更新委托记录
											$restModel->querysql("update `jjs_contract` set t_quantity = t_quantity + 1,lave_quantity = lave_quantity - 1,t_amount = t_amount + '".($newdealprice*(1-$parameter[0]["fee"]/2))."' where id = ".$contractid);
											
											//卖家拥有数量减少
											$restModel->querysql("update `jjs_user_gcode` set num = num -1 where user_id = ".$solderID);

											//将交易额结算给卖家############减仓时资金变化  Start##############
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".$newdealprice." where user_id = ".$solderID);
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","卖出增加可用余额","'.($newdealprice*(1-$parameter[0]["fee"]/2)).'","'.date("Y-m-d H:i:s",$sqstime).'")');
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","平台手续费扣除","-'.($newdealprice*($parameter[0]["fee"]/2)).'","'.date("Y-m-d H:i:s",$sqstime).'")');
											//将交易额结算给卖家############减仓时资金变化  Over##############
											
											//运营、代理运营、代理商给予相对应的分润
											$operate_fee = sprintf("%.4f",$newdealprice*($parameter[0]["operate"]/2));//运营手续费
											$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
											$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费

											$pre = $restModel->query("select tuser_code from `jjs_user` where id = ".$solderID);
											$pre1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre[0]["tuser_code"]."'");
											
											if($pre1[0]["type"]==2){//卖家上级是运营
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												
											}elseif($pre1[0]["type"]==3){//卖家上级是代理运营
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j0 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j0){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j0[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j0[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
											}elseif($pre1[0]["type"]==4){//卖家是代理商
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$agent_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j1){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j1[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j1[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
												$j2 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j1[0]["tuser_code"]."'");
												if($j2){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j2[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j2[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
											}
											/***卖家处理**********************  over *******************/

											/***买家处理********************** start *******************/
											
											//写入成交记录表
											$restModel->querysql("insert into `jjs_shares`(contract_id,user_id,g_code,cate,quantity,price,ctime) values('".$currentid[0]["last_insert_id()"]."'，'".$_SESSION["user_id"]."','".$g_code."','0','1','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");
											
											//更新委托记录
											$restModel->querysql("update `jjs_contract` set t_quantity = t_quantity + 1,lave_quantity = lave_quantity - 1,t_amount = t_amount + '".($newdealprice*(1+$parameter[0]["fee"]/2))."' where id = ".$currentid[0]["last_insert_id()"]);
											
											//买家拥有数量增加
											$is_val = $restModel->query("select * from `jjs_user_gcode` where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
											if($is_val){
												$restModel->querysql("update `jjs_user_gcode` set num = num + 1 where user_id = ".$_SESSION["user_id"]);
											}else{
												$restModel->querysql("insert into `jjs_user_gcode`(g_code,user_id,num) values('".$g_code."','".$_SESSION["user_id"]."')");
											}
											
											
											//运营、代理运营、代理商给予相对应的分润
											$pre2 = $restModel->query("select tuser_code from `jjs_user` where id = ".$_SESSION["user_id"]);
											$pre3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre2[0]["tuser_code"]."'");
											
											//将交易额结算结余部分返还给买家############持仓时资金变化  Start##############
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".($price-$newdealprice)*(1+$parameter[0]["fee"]/2).",bond = bond - '".$price*(1+$parameter[0]["fee"]/2)."' where user_id = ".$_SESSION["user_id"]);
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18011","持仓保证金","-'.$price*(1+$parameter[0]["fee"]/2).'","'.date("Y-m-d H:i:s").'")');
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18014","交易结算返还可用余额","'.($price-$newdealprice)*(1+$parameter[0]["fee"]/2).'","'.date("Y-m-d H:i:s",$sqstime).'")');
											//将交易额结算结余部分返还给买家############持仓时资金变化  Over##############
											if($pre3[0]["type"]==2){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");

											}elseif($pre3[0]["type"]==3){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
												if($j3){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j3[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j3[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
												
											}elseif($pre3[0]["type"]==4){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j4 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
												if($j4){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j4[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j4[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
												$j5 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j4[0]["tuser_code"]."'");
												if($j5){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j5[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j5[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
												
											}
											/***买家处理********************** over *******************/
										}
									}

								}else{
									for($i=1;$i<=$row["lave_quantity"];$i++){
										$sqsdate = file_get_contents('http://apistore.51daniu.cn:1218/?charset=utf-8&name='.$g_code."-S-".$row["price"]."-".$row["user_id"]."-".$row["id"].'&opt=get&auth=adminchen5188jjs');
										if($sqsdate == 'HTTPSQS_GET_END'){
											break;
										}else{
											//$quantity_sqs--;
											$sqsdateArr = explode("-",$sqsdate);
											
											$contractid = $sqsdateArr[4];//委托记录id(合约ID)
											$solderID = $sqsdateArr[3];//卖家UID
											$solderPrice = $sqsdateArr[2];//平仓价

											//最新成交价公式
											if($price <= $pre_price) $newdealprice = $price;
											if($solderPrice >= $pre_price) $newdealprice = $solderPrice;
											if($solderPrice < $pre_price && $pre_price < $price) $newdealprice = $pre_price;
											
											/***卖家处理**********************  start *******************/

											//写入成交记录
											$restModel->querysql("insert into `jjs_shares`(contract_id,user_id,g_code,cate,quantity,price,ctime) values('".$contractid."','".$solderID."','".$g_code."','1','1','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");
											
											//更新委托记录
											$restModel->querysql("update `jjs_contract` set t_quantity = t_quantity + 1,lave_quantity = lave_quantity - 1,t_amount = t_amount + '".($newdealprice*(1-$parameter[0]["fee"]/2))."' where id = ".$contractid);
											
											//将交易额结算给卖家############减仓时资金变化  Start##############
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".$newdealprice." where user_id = ".$solderID);
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","卖出增加可用余额","'.($newdealprice*(1-$parameter[0]["fee"]/2)).'","'.date("Y-m-d H:i:s",$sqstime).'")');
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","平台手续费扣除","-'.($newdealprice*($parameter[0]["fee"]/2)).'","'.date("Y-m-d H:i:s",$sqstime).'")');
											//将交易额结算给卖家############减仓时资金变化  Over##############
											
											//运营、代理运营、代理商给予相对应的分润
											$operate_fee = sprintf("%.4f",$newdealprice*($parameter[0]["operate"]/2));//运营手续费
											$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
											$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费

											$pre = $restModel->query("select tuser_code from `jjs_user` where id = ".$solderID);
											$pre1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre[0]["tuser_code"]."'");
											
											if($pre1[0]["type"]==2){//卖家上级是运营
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												
											}elseif($pre1[0]["type"]==3){//卖家上级是代理运营
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j0 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j0){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j0[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j0[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
											}elseif($pre1[0]["type"]==4){//卖家是代理商
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$agent_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
												if($j1){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j1[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j1[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
												$j2 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j1[0]["tuser_code"]."'");
												if($j2){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j2[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j2[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
											}
											/***卖家处理**********************  over *******************/

											/***买家处理********************** start *******************/
											
											//写入成交记录表
											$restModel->querysql("insert into `jjs_shares`(contract_id,user_id,g_code,cate,quantity,price,ctime) values('".$currentid[0]["last_insert_id()"]."'，'".$_SESSION["user_id"]."','".$g_code."','0','1','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");
											
											//更新委托记录
											$restModel->querysql("update `jjs_contract` set t_quantity = t_quantity + 1,lave_quantity = lave_quantity - 1,t_amount = t_amount + '".($newdealprice*(1-$parameter[0]["fee"]/2))."' where id = ".$currentid[0]["last_insert_id()"]);
											
											//运营、代理运营、代理商给予相对应的分润
											$pre2 = $restModel->query("select tuser_code from `jjs_user` where id = ".$_SESSION["user_id"]);
											$pre3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre2[0]["tuser_code"]."'");
											
											//将交易额结算结余部分返还给买家############持仓时资金变化  Start##############
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".($price-$newdealprice)*(1+$parameter[0]["fee"]/2).",bond = bond - '".$price*(1+$parameter[0]["fee"]/2)."' where user_id = ".$_SESSION["user_id"]);
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18011","持仓保证金","-'.$price*(1+$parameter[0]["fee"]/2).'","'.date("Y-m-d H:i:s").'")');
											$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18014","交易结算返还可用余额","'.($price-$newdealprice)*(1+$parameter[0]["fee"]/2).'","'.date("Y-m-d H:i:s",$sqstime).'")');
											
											//将交易额结算结余部分返还给买家############持仓时资金变化  Over##############
											if($pre3[0]["type"]==2){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");

											}elseif($pre3[0]["type"]==3){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
												if($j3){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j3[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j3[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
												
											}elseif($pre3[0]["type"]==4){
												
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
		
												$j4 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
												if($j4){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j4[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j4[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
		
												$j5 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j4[0]["tuser_code"]."'");
												if($j5){
													$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j5[0]["id"]);
													$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j5[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
												}
												
											}
											/***买家处理********************** over *******************/
										}
									}
									$quantity = $quantity - $row["lave_quantity"];//剩余所需处理的队列
								}
							}
							//撮合流程 交易原则：价格&时间优先#####################Over#######//by jessie at170111*/
							returnJson('200','操作成功');
						}
						else //队列不足
						{
							foreach($sqsrs as $row){
								
								for($i=1;$i<=$row["lave_quantity"];$i++){
									$sqsdate = file_get_contents('http://apistore.51daniu.cn:1218/?charset=utf-8&name='.$g_code."-S-".$row["price"]."-".$row["user_id"]."-".$row["id"].'&opt=get&auth=adminchen5188jjs');
									if($sqsdate == 'HTTPSQS_GET_END'){
										break;
									}else{
										//$quantity_sqs--;
										$sqsdateArr = explode("-",$sqsdate);
										
										$contractid = $sqsdateArr[4];//委托记录id(合约ID)
										$solderID = $sqsdateArr[3];//卖家UID
										$solderPrice = $sqsdateArr[2];//平仓价

										//最新成交价公式
										if($price <= $pre_price) $newdealprice = $price;
										if($solderPrice >= $pre_price) $newdealprice = $solderPrice;
										if($solderPrice < $pre_price && $pre_price < $price) $newdealprice = $pre_price;
										
										/***卖家处理**********************  start *******************/

										//写入成交记录
										$restModel->querysql("insert into `jjs_shares`(contract_id,user_id,g_code,cate,quantity,price,ctime) values('".$contractid."','".$solderID."','".$g_code."','1','1','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");
										
										//写入价格流水表
										$restModel->querysql("insert into `jjs_price_".date("Ymd",time())."`(g_code,price,ctime) values('".$g_code."','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");

										//更新委托记录
										$restModel->querysql("update `jjs_contract` set t_quantity = t_quantity + 1,lave_quantity = lave_quantity - 1,t_amount = t_amount + '".($newdealprice*(1-$parameter[0]["fee"]/2))."',status = 1 where id = ".$contractid);
										
										//将交易额结算给卖家############减仓时资金变化  Start##############
										$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".$newdealprice." where user_id = ".$solderID);
										$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","卖出增加可用余额","'.($newdealprice*(1-$parameter[0]["fee"]/2)).'","'.date("Y-m-d H:i:s",$sqstime).'")');
										$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$solderID.'","18012","平台手续费扣除","-'.($newdealprice*($parameter[0]["fee"]/2)).'","'.date("Y-m-d H:i:s",$sqstime).'")');
										//将交易额结算给卖家############减仓时资金变化  Over##############
										
										//运营、代理运营、代理商给予相对应的分润
										$operate_fee = sprintf("%.4f",$newdealprice*($parameter[0]["operate"]/2));//运营手续费
										$agent_operation_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent_operation"]/2));//代理运营手续费
										$agent_fee = sprintf("%.4f",$newdealprice*($parameter[0]["agent"]/2));//代理商手续费

										$pre = $restModel->query("select tuser_code from `jjs_user` where id = ".$solderID);
										$pre1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre[0]["tuser_code"]."'");
										
										if($pre1[0]["type"]==2){//卖家上级是运营
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre1[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											
										}elseif($pre1[0]["type"]==3){//卖家上级是代理运营
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre1[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
	
											$j0 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
											if($j0){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j0[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j0[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											}
	
										}elseif($pre1[0]["type"]==4){//卖家是代理商
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre1[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre1[0]["id"]."','1333','卖出交易手续费分润','".$agent_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
	
											$j1 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre1[0]["tuser_code"]."'");
											if($j1){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j1[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j1[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											}
	
											$j2 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j1[0]["tuser_code"]."'");
											if($j2){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j2[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j2[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											}
										}
										/***卖家处理**********************  over *******************/

										/***买家处理********************** start *******************/
										
										//写入成交记录表
										$restModel->querysql("insert into `jjs_shares`(contract_id,user_id,g_code,cate,quantity,price,ctime) values('".$currentid[0]["last_insert_id()"]."'，'".$_SESSION["user_id"]."','".$g_code."','0','1','".$newdealprice."','".date("Y-m-d H:i:s",$sqstime)."')");
										
										//更新委托记录
										$restModel->querysql("update `jjs_contract` set t_quantity = t_quantity + 1,lave_quantity = lave_quantity - 1,t_amount = t_amount + '".($newdealprice*(1+$parameter[0]["fee"]/2))."' where id = ".$currentid[0]["last_insert_id()"]);
										
										//运营、代理运营、代理商给予相对应的分润
										$pre2 = $restModel->query("select tuser_code from `jjs_user` where id = ".$_SESSION["user_id"]);
										$pre3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre2[0]["tuser_code"]."'");
										
										//将交易额结算结余部分返还给买家############持仓时资金变化  Start##############
										$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount + ".($price-$newdealprice)*(1+$parameter[0]["fee"]/2).",bond = bond - '".$price*(1+$parameter[0]["fee"]/2)."' where user_id = ".$_SESSION["user_id"]);
										$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18011","持仓保证金","-'.$price*(1+$parameter[0]["fee"]/2).'","'.date("Y-m-d H:i:s").'")');
										$restModel->querysql('insert into jjs_detail(user_id,cate,remark,amount,ctime) values("'.$_SESSION["user_id"].'","18014","交易结算返还可用余额","'.($price-$newdealprice)*(1+$parameter[0]["fee"]/2).'","'.date("Y-m-d H:i:s",$sqstime).'")');
										//将交易额结算结余部分返还给买家############持仓时资金变化  Over##############
										if($pre3[0]["type"]==2){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");

										}elseif($pre3[0]["type"]==3){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
	
											$j3 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
											if($j3){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j3[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j3[0]["id"]."','1333','卖出交易手续费分润','".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											}
	
											
										}elseif($pre3[0]["type"]==4){
											
											$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_fee." where user_id = ".$pre3[0]["id"]);
											$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$pre3[0]["id"]."','1333','卖出交易手续费分润','-".$agent_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
	
											$j4 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$pre3[0]["tuser_code"]."'");
											if($j4){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$agent_operation_fee." where user_id = ".$j4[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j4[0]["id"]."','1333','卖出交易手续费分润','-".$agent_operation_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											}
	
											$j5 = $restModel->query("select id,type,tuser_code from `jjs_user` where referral_code = '".$j4[0]["tuser_code"]."'");
											if($j5){
												$restModel->querysql("update `jjs_user_finance` set tempamount = tempamount+".$operate_fee." where user_id = ".$j5[0]["id"]);
												$restModel->querySql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$j5[0]["id"]."','1333','卖出交易手续费分润','-".$operate_fee."','".date("Y-m-d H:i:s",$sqstime)."')");
											}
											
										}
										/***买家处理********************** over *******************/
									}
								}
							}			
							returnJson('200','委托成功');
						}
					}else{
						returnJson("500","可用余额不足，请充值");
					}
				}else{
					returnJson("500","交易密码错误");
				}
			}else{
				returnJson("500","委托价格异常");
			}
		}else{
			returnJson("500","数量已超出可买数量");
		}
	}

	//交易卖出
	/*public function sold()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$restModel=D("Rest");
		$g_code = trim(getgpc("g_code"));
	    $quantity = intval(getgpc('quantity'));//委托数量
	    $price = getgpc('price');//委托价
	    $tpassword = md5(getgpc("tpassword"));
		
		//商品信息
		$goods = D("Rest")->query("select user_id,code,title,price,up,down,num,holdnum,surplus_num,ctime from `jjs_goods_release` where code = '".$g_code."'");
		$nowtime = time();
		$opentime = strtotime(date("Y-m-d 04:00:00",time()));
		$closetime = strtotime(date("Y-m-d 21:00:00",time()));
		if($nowtime < $opentime){
			returnJson("500","今日暂未开盘");
		}elseif($nowtime > $closetime){
			returnJson("500","今日已收盘");
		}
		
		$coin = D("Rest")->query("select coin from `jjs_user_finance` where user_id = ".$_SESSION["user_id"]);
		$entrust = D("Rest")->query("select sum(lave_quantity) as lave_quantity from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and status = 0 and cate = 1 and is_del = 0");//委托卖出但未成交且未撤销的
		$dealnum = $coin[0]["coin"] - $entrust[0]["quantity"];//可卖数量
		
		//判断所传数量是否正确
		if($quantity <= $dealnum)
		{
			//判断所传价格是否介于跌停和涨停价之间
			if($goods[0]["down"]<$price && $price<$goods[0]["up"]){

				$user_tpassword = $restModel->query("select tpassword from `jjs_user` where id = ".$_SESSION["user_id"]);

				if($tpassword == $user_tpassword[0]["tpassword"]){//判断交易密码是否正确
					//先将持有减少
					//$restModel->querysql("update `jjs_user_finance` set coin = coin - ".$quantity." where user_id = ".$_SESSION["user_id"]);	
					//$this->contract($g_code,$quantity,$price,1);
					$restModel->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,lave_quantity,status,ctime) values('".$_SESSION["user_id"]."','".$g_code."','1','".$quantity."','".$."','')");
					returnJson('200','委托成功');

				}else{
					returnJson("500","交易密码错误");
				}
			}else{

				returnJson("500","委托价格异常");

			}
		}else{
			returnJson("500","数量已超出可卖数量");
		}
		

	}
	
	//创建商品价格流水表
	private function create_price()
	{
		$sql  = "CREATE TABLE `jjs_price_".date("Ymd")."` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`g_code` varchar(200) DEFAULT NULL COMMENT '商品代号',
			`price` decimal(11,2) DEFAULT NULL COMMENT '最新价格（即最新成交价）',
			`num` int(11) DEFAULT 0 COMMENT '以此价格成交的数量',
			`ctime` datetime DEFAULT NULL COMMENT '更新时间',
			PRIMARY KEY (`id`)
		)ENGINE=InnoDB DEFAULT CHARSET=utf8;";

		if(D('Rest')->querysql($sql))
		{
			//returnJson('200',"创建成功");
		}
		
	}

	//创建交易记录流水表
	private function create_sqs()
	{
		$sql  = "CREATE TABLE `jjs_sqs_".date("Ymd")."` (
			`id` int(11) NOT NULL AUTO_INCREMENT,
			`s_key` varchar(200) DEFAULT NULL,
			`s_value` varchar(200) DEFAULT NULL,
			`ctime` datetime DEFAULT NULL COMMENT '更新时间',
			PRIMARY KEY (`id`)
		) ENGINE=InnoDB DEFAULT CHARSET=utf8;";

		if(D('Rest')->querysql($sql))
		{
			//returnJson('200',"创建成功");
		}
		
	}

	//发售交易列表
	public function dealist()
	{
		$goods = D("Rest")->query("select id,user_id,code,price,title,num,surplus_num from `jjs_goods_release` where release_status = 1");
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				$highestprice = array();
				$lowestprice = array();
				$newprice = array();
				$totalprice = array();
				$num = array();
				foreach($pricetables as $k=>$v){
					//最高价、最低价
					$res = D("Rest")->query("select max(price) as highestprice,min(price) as lowestprice from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($res[0]["highestprice"]) $highestprice[] = $res[0]["highestprice"];
					if($res[0]["lowestprice"]) $lowestprice[] = $res[0]["lowestprice"];
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc limit 1");
					if($deal[0]["price"]) $newprice[] = $deal[0]["price"];
					
					//价格统计
					$total = D("Rest")->query("select sum(price) as totalprice,count(1) as count from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($total[0]["totalprice"]){ 
						$totalprice["totalprice"] += $total[0]["totalprice"];
						$totalprice["count"] += $total[0]["count"];
					}

					//成交量统计
					$totalnum = D("Rest")->query("select sum(num) as dealnum from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($totalnum[0]["dealnum"]){ 
						$dealnum += $totalnum[0]["dealnum"];
					}

				}
				$highestprice[] = $vo["price"];
				$lowestprice[] = $vo["price"];
				rsort($highestprice);
				sort($lowestprice);

				$opentime = date("Y-m-d 04:00:00",time());
				$closetime = date("Y-m-d 21:00:00",time()-24*3600);
				$openprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time())."` where g_code = '".$vo["code"]."' and ctime > '".$opentime."' order by ctime asc limit 1");
				$closeprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time()-24*3600)."` where g_code = '".$vo["code"]."' and ctime < '".$closetime."' order by ctime desc limit 1");

				
				if($newprice){ 
					$range = end($newprice)-$vo["price"];
					$rangerate = sprintf("%.2f",($range/$vo["price"])*100)."%";
					$goods[$key]["newprice"] = end($newprice); //最新价
					$goods[$key]["range"] = $range; //涨跌
					$goods[$key]["rangerate"] = $rangerate; //幅度
				}else{ 
					$goods[$key]["newprice"] = $vo["price"];
					$goods[$key]["range"] = 0; //涨跌
					$goods[$key]["rangerate"] = 0; //幅度
				}

				//是否持有
				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}

				//是否加入自选
				$is_optional = D("Rest")->query("select * from `jjs_optional` where g_code = '".$vo["code"]."' and user_id = ".$_SESSION["user_id"]);
				if($is_optional){
					
					if($is_optional[0]["is_del"]==0) $goods[$key]["is_del"] = "取消自选"; else $goods[$key]["is_del"] = "加入自选";//是否加入自选
				}else{
					$goods[$key]["is_del"] = "加入自选";
				}

				//限量统计
				$coin = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and status = 0 and cate = 1 and is_del = 0");
				$surplus = $vo["surplus_num"]-$coin[0]["quantity"];//可卖数量
				$goods[$key]["surplus"] = $surplus;

				if($highestprice) $goods[$key]["highestprice"] = $highestprice[0]; else $goods[$key]["highestprice"] = $vo["price"];//最高价
				if($lowestprice) $goods[$key]["lowestprice"] = $lowestprice[0]; else $goods[$key]["lowestprice"] = $vo["price"];//最低价
				if($totalprice) $goods[$key]["avarageprice"] = round($totalprice["totalprice"]/$totalprice["count"],2); else $goods[$key]["avarageprice"] = $vo["price"];//均价
				if($dealnum) $goods[$key]["dealnum"] = $dealnum; else $goods[$key]["dealnum"] = 0;//成交量

				if($openprice[0]["price"] && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif($openprice[0]["price"] && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					if($now[0]["t_price"]) $goods[$key]["closeprice"] = $now[0]["t_price"];else $goods[$key]["closeprice"] = $vo["price"];

				}elseif(empty($openprice[0]["price"]) && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $closeprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif(empty($openprice[0]["price"]) && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					
					if($now[0]["t_price"]) {
						$goods[$key]["openprice"] = $now[0]["t_price"]; //开盘价
						$goods[$key]["closeprice"] = $now[0]["t_price"];
					}else{
						$goods[$key]["openprice"] = $vo["price"]; //开盘价
						$goods[$key]["closeprice"] = $vo["price"];//昨日收盘价
					}

				}
				
			}
		}


		
		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		

	}

	//入场登记详情
	public function release_detail()
	{
		$g_code = trim(getgpc("g_code"));
		$goods = D("Rest")->query("select id,user_id,code,price,title,num,surplus_num from `jjs_goods_release` where code = '".$g_code."'");
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				foreach($pricetables as $k=>$v){
					//最高价、最低价
					$res = D("Rest")->query("select max(price) as highestprice,min(price) as lowestprice from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($res[0]["highestprice"]) $highestprice[] = $res[0]["highestprice"];
					if($res[0]["lowestprice"]) $lowestprice[] = $res[0]["lowestprice"];
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc limit 1");
					if($deal[0]["price"]) $newprice[] = $deal[0]["price"];
					
					//价格统计
					$total = D("Rest")->query("select sum(price) as totalprice,count(1) as count from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($total[0]["totalprice"]){ 
						$totalprice["totalprice"] += $total[0]["totalprice"];
						$totalprice["count"] += $total[0]["count"];
					}

					//成交量统计
					$totalnum = D("Rest")->query("select sum(num) as dealnum from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($totalnum[0]["dealnum"]){ 
						$dealnum += $totalnum[0]["dealnum"];
					}
				}
				$highestprice[] = $vo["price"];
				$lowestprice[] = $vo["price"];
				rsort($highestprice);
				sort($lowestprice);

				$opentime = date("Y-m-d 04:00:00",time());
				$closetime = date("Y-m-d 21:00:00",time()-24*3600);
				$openprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time())."` where g_code = '".$vo["code"]."' and ctime > '".$opentime."' order by ctime asc limit 1");
				$closeprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time()-24*3600)."` where g_code = '".$vo["code"]."' and ctime < '".$closetime."' order by ctime desc limit 1");
				
				//市价与昨日收盘的之间的关系
				if($closeprice[0]["price"]){
					$mcloseprice = $vo["price"]-$closeprice[0]["price"];
					$mcloserate = sprintf("%.2f",($mcloseprice/$closeprice[0]["price"])*100)."%";
				}else{
					$mcloseprice = "-";
					$mcloserate = "-";
				}
				$goods[$key]["mcloseprice"] = $mcloseprice; //mapp数据
				$goods[$key]["mcloserate"] = $mcloserate; //mapp数据
				
				if($newprice){ 
					$range = end($newprice)-$vo["price"];
					$rangerate = sprintf("%.2f",($range/$vo["price"])*100)."%";
					$goods[$key]["newprice"] = end($newprice); //最新价
					$goods[$key]["range"] = $range; //涨跌
					$goods[$key]["rangerate"] = $rangerate; //幅度
					
				}else{ 
					$goods[$key]["newprice"] = $vo["price"];
					$goods[$key]["range"] = 0; //涨跌
					$goods[$key]["rangerate"] = 0; //幅度
				}

				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}

				//是否持有
				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}
				//是否加入自选
				$is_optional = D("Rest")->query("select * from `jjs_optional` where g_code = '".$vo["code"]."' and user_id = ".$_SESSION["user_id"]);
				//总量、总额
				$total = $vo["num"]*$vo["price"];
				$b = 1000;
				$c = 10000;
				$d = 100000000;
				 
				if($total>$b){
					if ($total<$c) {
						$total = floor($total/$b).'千';
					}elseif($total<$d) {
						$total = (floor(($total/$c)*100)/100).'万';
					}else{
						$total = (floor(($total/$d)*100)/100).'亿';
					}
				}

				//现量统计
				$coin = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and status = 0 and cate = 1 and is_del = 0");
				$surplus = $vo["surplus_num"]-$coin[0]["quantity"];//现量
				$goods[$key]["surplus"] = $surplus;
				$goods[$key]["total"] = $total;//总额
				if($highestprice) $goods[$key]["highestprice"] = $highestprice[0]; else $goods[$key]["highestprice"] = $vo["price"];//最高价
				if($lowestprice) $goods[$key]["lowestprice"] = $lowestprice[0]; else $goods[$key]["lowestprice"] = $vo["price"];//最低价
				if($totalprice) $goods[$key]["avarageprice"] = round($totalprice["totalprice"]/$totalprice["count"],2); else $goods[$key]["avarageprice"] = $vo["price"];//均价
				if($dealnum) $goods[$key]["dealnum"] = $dealnum; else $goods[$key]["dealnum"] = 0;//成交量
				if($is_optional){
					if($is_optional[0]["is_del"]==0) $goods[$key]["is_del"] = "取消自选"; else $goods[$key]["is_del"] = "加入自选";//是否加入自选
				}else{
					$goods[$key]["is_del"] = "加入自选";
				}
				
				if($openprice[0]["price"] && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif($openprice[0]["price"] && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					if($now[0]["t_price"]) $goods[$key]["closeprice"] = $now[0]["t_price"];else $goods[$key]["closeprice"] = $vo["price"];

				}elseif(empty($openprice[0]["price"]) && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $closeprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif(empty($openprice[0]["price"]) && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					
					if($now[0]["t_price"]) {
						$goods[$key]["openprice"] = $now[0]["t_price"]; //开盘价
						$goods[$key]["closeprice"] = $now[0]["t_price"];
					}else{
						$goods[$key]["openprice"] = $vo["price"]; //开盘价
						$goods[$key]["closeprice"] = $vo["price"];//昨日收盘价
					}

				}
				
			}
			//成绩记录
			$deal = D("Rest")->query("select sum(quantity) as quantity,t_price,deal_time from `jjs_contract` where status>0 and g_code = '".$g_code."' group by deal_time,cate");
			$goods[$key]["deal"] = $deal;
		}


		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
	}

	//加入自选
	public function add_optional()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$is_optional = D("Rest")->query("select * from `jjs_optional` where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
		if($is_optional){
			if($is_optional[0]["is_del"] == 0){
				returnJson("500","该商品已在自选列表");
			}elseif($is_optional[0]["is_del"] == 1){
				$res = D("Rest")->querysql("update `jjs_optional` set is_del = 0 where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
			}
		}else{
			$res = D("Rest")->querysql("insert into `jjs_optional`(g_code,user_id,ctime) values('".$g_code."','".$_SESSION["user_id"]."','".time()."')");
		}
		if($res){
			returnJson("200","加入成功");
		}else{
			returnJson("500","加入失败");
		}
		
	}

	//取消自选
	public function cancle_optional()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$res = D("Rest")->querysql("update `jjs_optional` set is_del = 1 where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
		if($res){
			returnJson("200","取消成功");
		}else{
			returnJson("500","取消失败");
		}
	}

	//删除自选
	public function del_optional()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$res = D("Rest")->querysql("delete from `jjs_optional` where user_id = ".$_SESSION["user_id"]." and g_code = '".$g_code."'");
		if($res){
			returnJson("200","删除成功");
		}else{
			returnJson("500","删除失败");
		}
	}

	//自选列表
	public function optionalist()
	{
		$goods = D("Rest")->query("select R.id,R.user_id,R.price,R.title,R.code,R.num,R.surplus_num from `jjs_goods_release` R,`jjs_optional` O where O.g_code = R.code and O.is_del = 0");
		$pricetables = D("Rest")->query("show tables like '%price%'");
		foreach($goods as $key=>$vo){
			if($pricetables){
				$highestprice = array();
				$lowestprice = array();
				$newprice = array();
				$totalprice = array();
				$num = array();
				foreach($pricetables as $k=>$v){
					//最高价、最低价
					$res = D("Rest")->query("select max(price) as highestprice,min(price) as lowestprice from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($res[0]["highestprice"]) $highestprice[] = $res[0]["highestprice"];
					if($res[0]["lowestprice"]) $lowestprice[] = $res[0]["lowestprice"];
					
					//最新价
					$deal = D("Rest")->query("select price from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc limit 1");
					if($deal[0]["price"]) $newprice[] = $deal[0]["price"];
					
					//价格统计
					$total = D("Rest")->query("select sum(price) as totalprice,count(1) as count from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($total[0]["totalprice"]){ 
						$totalprice["totalprice"] += $total[0]["totalprice"];
						$totalprice["count"] += $total[0]["count"];
					}

					//成交量统计
					$totalnum = D("Rest")->query("select sum(num) as dealnum from `".$v["Tables_in_jjs (%price%)"]."` where g_code = '".$vo["code"]."' order by ctime desc");
					if($totalnum[0]["dealnum"]){ 
						$dealnum += $totalnum[0]["dealnum"];
					}

				}
				$highestprice[] = $vo["price"];
				$lowestprice[] = $vo["price"];
				rsort($highestprice);
				sort($lowestprice);

				$opentime = date("Y-m-d 04:00:00",time());
				$closetime = date("Y-m-d 21:00:00",time()-24*3600);
				$openprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time())."` where g_code = '".$vo["code"]."' and ctime > '".$opentime."' order by ctime asc limit 1");
				$closeprice = D("Rest")->query("select price from `jjs_price_".date("Ymd",time()-24*3600)."` where g_code = '".$vo["code"]."' and ctime < '".$closetime."' order by ctime desc limit 1");

				
				if($newprice){ 
					$range = end($newprice)-$vo["price"];
					$rangerate = sprintf("%.2f",($range/$vo["price"])*100)."%";
					$goods[$key]["newprice"] = end($newprice); //最新价
					$goods[$key]["range"] = $range; //涨跌
					$goods[$key]["rangerate"] = $rangerate; //幅度
				}else{ 
					$goods[$key]["newprice"] = $vo["price"];
					$goods[$key]["range"] = 0; //涨跌
					$goods[$key]["rangerate"] = 0; //幅度
				}

				
				if($vo["user_id"] == $_SESSION["user_id"]){
					$goods[$key]["is_hold"] = 1;
				}else{
					$goods[$key]["is_hold"] = 0;
				}

				//现量统计
				$coin = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and status = 0 and cate = 1 and is_del = 0");
				$surplus = $vo["surplus_num"]-$coin[0]["quantity"];//现量
				$goods[$key]["surplus"] = $surplus;
				
				if($highestprice) $goods[$key]["highestprice"] = $highestprice[0]; else $goods[$key]["highestprice"] = $vo["price"];//最高价
				if($lowestprice) $goods[$key]["lowestprice"] = $lowestprice[0]; else $goods[$key]["lowestprice"] = $vo["price"];//最低价
				if($totalprice) $goods[$key]["avarageprice"] = round($totalprice["totalprice"]/$totalprice["count"],2); else $goods[$key]["avarageprice"] = $vo["price"];//均价
				if($dealnum) $goods[$key]["dealnum"] = $dealnum; else $goods[$key]["dealnum"] = 0;//成交量
				
				if($openprice[0]["price"] && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif($openprice[0]["price"] && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					$goods[$key]["openprice"] = $openprice[0]["price"]; //开盘价
					if($now[0]["t_price"]) $goods[$key]["closeprice"] = $now[0]["t_price"];else $goods[$key]["closeprice"] = $vo["price"];

				}elseif(empty($openprice[0]["price"]) && $closeprice[0]["price"]){ 

					$goods[$key]["openprice"] = $closeprice[0]["price"]; //开盘价
					$goods[$key]["closeprice"] = $closeprice[0]["price"];//昨日收盘价

				}elseif(empty($openprice[0]["price"]) && empty($closeprice[0]["price"])){ 
					$now = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status != 0 and ctime < '".date("Y-m-d",time()-24*3600)."' order by ctime desc limit 1 ");
					
					if($now[0]["t_price"]) {
						$goods[$key]["openprice"] = $now[0]["t_price"]; //开盘价
						$goods[$key]["closeprice"] = $now[0]["t_price"];
					}else{
						$goods[$key]["openprice"] = $vo["price"]; //开盘价
						$goods[$key]["closeprice"] = $vo["price"];//昨日收盘价
					}

				}
			}
		}
		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		
		

	}

	
	//行情分时图数据
	public function quotation()
	{
		
		$g_code = trim(getgpc("g_code"));
		$id = intval(getgpc("id"));
		$direction = trim(getgpc("direction"));
		$range = trim(getgpc("range"));
		if($direction && $id){
			if($direction == "left"){ 

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id<".$id." order by id desc limit 1");
				if(empty($goods)) returnJson("500","没有啦");

			}elseif($direction == "right"){

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id>".$id." order by id asc limit 1");
				if(empty($goods)) returnJson("500","没有啦");
			}
		}else{
			$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where code = '".$g_code."'");
			
		}
		
		if($range == "日"){

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where to_days(`deal_time`) = to_days(now()) and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "周"){

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where YEARWEEK(date_format(`deal_time`,'%Y-%m-%d')) = YEARWEEK(now()) and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "月"){

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where PERIOD_DIFF( date_format( now( ) , '%Y%m' ) , date_format(deal_time, '%Y%m')) =0 and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "季"){
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where QUARTER(`deal_time`)=QUARTER(now()) and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");
		}elseif($range == "1"){
			
			$ltime = date("Y-m-d H:i:s",time()-60);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "3"){

			$ltime = date("Y-m-d H:i:s",time()-60*3);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "5"){

			$ltime = date("Y-m-d H:i:s",time()-60*5);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "15"){

			$ltime = date("Y-m-d H:i:s",time()-60*15);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "30"){

			$ltime = date("Y-m-d H:i:s",time()-60*30);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "60"){

			$ltime = date("Y-m-d H:i:s",time()-60*60);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "120"){

			$ltime = date("Y-m-d H:i:s",time()-60*120);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}elseif($range == "240"){

			$ltime = date("Y-m-d H:i:s",time()-60*240);
			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");

		}else{

			$r = D("Rest")->query("select convert(deal_time,char(16)) as newtime,MAX(t_price) as highestprice,MIN(t_price) as lowestprice from jjs_contract where g_code = '".$goods[0]["code"]."' group by convert(deal_time,char(16))");
			unset($r[0]);
		}

		
		foreach($r as $k=>$v){
			$r1 = D("Rest")->query("select t_price from `jjs_contract` where deal_time like '%".$v["newtime"]."%' and g_code = '".$goods[0]["code"]."' group by status order by deal_time asc");
			$r[$k]["openprice"] = reset($r1)["t_price"];
			$r[$k]["closeprice"] = end($r1)["t_price"];
		}
		
		$list = array();
		foreach($r as $key=>$vo){
			$list[$key]["deal_time"] = strtotime($vo["newtime"])."000";
			$list[$key]["openprice"] = $vo["openprice"];
			$list[$key]["highestprice"] = $vo["highestprice"];
			$list[$key]["lowestprice"] = $vo["lowestprice"];
			$list[$key]["closeprice"] = $vo["closeprice"];
		}
			
		foreach($list as $k=>$v){
			$arr = array();
			$arr[] = $v["deal_time"];
			$arr[] = $v["openprice"];
			$arr[] = $v["highestprice"];
			$arr[] = $v["lowestprice"];
			$arr[] = $v["closeprice"];
			$result[] = $arr;
		}
		returnJson("200","",array("id"=>$goods[0]["id"],"g_code"=>$goods[0]["code"],"k_data"=>$result));
		

	}

	

	//持仓记录
	public function holdlist()
	{
		$goods = D("Rest")->query("select C.cate,C.ctime,R.id,R.code,R.title,R.price as mprice,R.user_id,R.num,R.surplus_num,sum(quantity) as quantity,C.status,C.t_price,C.is_del,C.status from `jjs_contract` C,`jjs_goods_release` R where C.g_code = R.code and C.user_id = ".$_SESSION["user_id"]." group by C.ctime,C.cate,C.g_code order by C.ctime desc");
		$para = D("Rest")->query("select * from	`jjs_deal_setting`");
		foreach($goods as $key=>$vo){
			
			if($vo["status"]>0){
				//最新价
				$newprice = D("Rest")->query("select t_price from `jjs_contract` where g_code = '".$vo["code"]."' and status > 0 order by deal_time desc limit 1");
				//交易手续费
				$fee = sprintf("%.4f",($vo["quantity"]*$vo["t_price"]*($para[0]["fee"]/2)));
				//成本
				$cost = sprintf("%.4f",($vo["quantity"]*$vo["t_price"]+$fee)/$vo["quantity"]);
				//盈亏
				$shares =(sprintf("%.4f", (($newprice[0]["t_price"]*$vo["quantity"])-($vo["quantity"]*$vo["t_price"]+$fee))/($vo["quantity"]*$vo["t_price"]+$fee))*100)."%";
			}else{
				//成本
				$cost = '-';
				//盈亏
				$shares = '-';
			}

			//可用
			$available = D("Rest")->query("select sum(quantity) as quantity from `jjs_contract` where g_code = '".$vo["code"]."' and user_id = ".$_SESSION["user_id"]." and ctime = '".$vo["ctime"]."' and status > 0 and cate = ".$vo["cate"]);
			if($newprice[0]["t_price"])$goods[$key]["newprice"] = $newprice[0]["t_price"]; else $goods[$key]["newprice"] = $goods[0]["mprice"];//最新价(现价)
			$goods[$key]["shares"] = $shares; //盈亏
			$goods[$key]["cost"] = $cost; //成本
			if($available[0]["quantity"])$goods[$key]["available"] = $available[0]["quantity"];else  $goods[$key]["available"] = 0;//可用
			
		}

		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
	}

	//交易撤单
	public function cancle_deal()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$ctime = trim(getgpc("ctime"));
		$cate = intval(getgpc("cate"));
		$r = D("Rest")->query("select sum(price) as price from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");
		if($r){
			$res = D("Rest")->querysql("update `jjs_contract` set is_del = 1 where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");
			D("Rest")->querysql("insert into `jjs_detail` (user_id,cate,remark,amount,ctime) values('".$_SESSION["user_id"]."','2552','交易撤单','".$r[0]["price"]."','".date("Y-m-d H:i:s")."')");
			if($res){
				returnJson("200","撤单成功");
			}else{
				returnJson("500","撤单失败");
			}
		}else{
			returnJson("500","撤单失败");
		}
		
	}

	//持仓记录查询
	public function holdetail()
	{
		$ctime = trim(getgpc("ctime"));
		$cate = intval(getgpc("cate"));
		$g_code = trim(getgpc("g_code"));
		$para = D("Rest")->query("select * from	`jjs_deal_setting`");
		if($ctime && $g_code){
			$goods = D("Rest")->query("select C.cate,C.price,C.ctime,C.deal_time,R.id,R.code,R.title,R.user_id,R.num,sum(quantity) as quantity,C.status,C.t_price,C.is_del,R.price as mprice from `jjs_contract` C,`jjs_goods_release` R where C.g_code = R.code and C.user_id = ".$_SESSION["user_id"]." and C.ctime = '".$ctime."' and C.cate = ".$cate." and C.g_code = '".$g_code."'");
			foreach($goods as $key=>$vo){
				//echo $vo["quantity"]*$vo["t_price"]*($para[0]["fee"]/2);
				//交易手续费
				$fee = sprintf("%.4f",($vo["quantity"]*$vo["t_price"]*($para[0]["fee"]/2)));
				$goods[$key]["fee"] = $fee;

				//方向
				if($vo["cate"]==0){
					$goods[$key]["cate"] = "买入";
					$goods[$key]["total"] = $vo["quantity"]*$vo["t_price"]+$fee; //费用合计
				}else{
					$goods[$key]["cate"] = "卖出";
					$goods[$key]["total"] = $vo["quantity"]*$vo["t_price"]-$fee; //费用合计
				}

				//交易额
				$goods[$key]["turnover"] = $vo["quantity"]*$vo["t_price"];

				//操作类型
				if($vo["is_del"]==0){
					if($vo["cate"]==0){
						$goods[$key]["type"] = "直接买入"; //方向
					}else{
						$goods[$key]["type"] = "直接卖出"; //方向
					}
				}else{
					$goods[$key]["type"] = "委托撤单"; //方向
				}
				
				if(substr($vo["deal_time"],0,1)==0) $goods[$key]["deal_time"] = "--"; //成交时间
				if(substr($vo["t_price"],0,1)==0) $goods[$key]["t_price"] = "--"; //成交价格

			}
		}

		if($goods){
			returnJson("200","请求成功",$goods);
		}else{
			returnJson("200","暂无数据");
		}
		
	}

	//删除交易
	public function del_deal()
	{
		if(empty($_SESSION['user_id']))
	    {
			returnJson('403', '请先登录');
	    }
		$g_code = trim(getgpc("g_code"));
		$ctime = trim(getgpc("ctime"));
		$cate = intval(getgpc("cate"));
		$r = D("Rest")->query("select * from `jjs_contract`  where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");
		if($r){
			$res = D("Rest")->querysql("delete from `jjs_contract` where g_code = '".$g_code."' and user_id = ".$_SESSION["user_id"]." and cate = ".$cate." and ctime = '".$ctime."'");

			if($res){
				returnJson("200","删除成功");
			}else{
				returnJson("500","删除失败");
			}
		}else{
			returnJson("500","删除失败");
		}
	}

	//K线图
	public function K_data()
	{
		$g_code = trim(getgpc("g_code"));
		$id = intval(getgpc("id"));
		$direction = trim(getgpc("direction"));
		$range = trim(getgpc("range"));
		if($direction && $id){
			if($direction == "left"){ 

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id<".$id." order by id desc limit 1");
				if(empty($goods)) returnJson("500","没有啦");

			}elseif($direction == "right"){

				$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where id>".$id." order by id asc limit 1");
				if(empty($goods)) returnJson("500","没有啦");
			}
		}else{
			$goods = D("Rest")->query("select id,user_id,code,price,title,num from `jjs_goods_release` where code = '".$g_code."'");
			
		}
		
		if($range == "日"){

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where to_days(`deal_time`) = to_days(now()) and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "周"){

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where YEARWEEK(date_format(`deal_time`,'%Y-%m-%d')) = YEARWEEK(now()) and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "月"){

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where PERIOD_DIFF( date_format( now( ) , '%Y%m' ) , date_format(deal_time, '%Y%m')) =0 and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "季"){
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where QUARTER(`deal_time`)=QUARTER(now()) and g_code = '".$goods[0]["code"]."' group by deal_time");
		}elseif($range == "1"){
			
			$ltime = date("Y-m-d H:i:s",time()-60);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "3"){

			$ltime = date("Y-m-d H:i:s",time()-60*3);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "5"){

			$ltime = date("Y-m-d H:i:s",time()-60*5);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "15"){

			$ltime = date("Y-m-d H:i:s",time()-60*15);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "30"){

			$ltime = date("Y-m-d H:i:s",time()-60*30);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "60"){

			$ltime = date("Y-m-d H:i:s",time()-60*60);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "120"){

			$ltime = date("Y-m-d H:i:s",time()-60*120);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}elseif($range == "240"){

			$ltime = date("Y-m-d H:i:s",time()-60*240);
			$r = D("Rest")->query("select deal_time,t_price from `jjs_contract` where deal_time>='".$ltime."' and g_code = '".$goods[0]["code"]."' group by deal_time");

		}else{

			$r = D("Rest")->query("select deal_time,t_price from jjs_contract where g_code = '".$goods[0]["code"]."' group by deal_time");
			unset($r[0]);
		}
		
			
		foreach($r as $k=>$v){
			$arr = array();
			$arr[] = strtotime($v["deal_time"])."000";
			$arr[] = $v["t_price"];
			$result[] = $arr;
		}
		returnJson("200","",array("id"=>$goods[0]["id"],"g_code"=>$goods[0]["code"],"k_data"=>$result));
	}

	public function sqs()
	{
		$res = D("Rest")->query("select * from `jjs_contract` where status = -1");
		foreach($res as $k=>$v){
			for($i=1;$i<=$v["quantity"];$i++){
				$name = $v["g_code"]."-".($v["cate"]==0)?"B":"S"."-".$v["price"]."-".strtotime($v["ctime"]);
				$data = $v["g_code"]."-".($v["cate"]==0)?"B":"S"."-".$v["price"]."-".$v["user_id"].rand(100000000000000000,999999999999999999);
				
				
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$name."&opt=put&data=".$data."&auth=adminchen5188jjs");

				
			}
			D("Rest")->querysql("update `jjs_contract` set status = 0 where id = ".$v["id"]);
		}
	}

	//收盘
	public function close()
	{
		$res = D("Rest")->query("select * from `jjs_contract` where status <=0 and ctime like '%".date("Y-m-d",time())."%'");
		foreach($res as $k=>$v){
			$name = $v["g_code"]."-".($v["cate"]==0?"B":"S")."-".$v["price"].$v["user_id"].strtotime($v["ctime"]);
			for($i=1;$i<=$v["quantity"];$i++){
				$data = file_get_contents("http://apistore.51daniu.cn:1218/?name=".$name."&opt=get&auth=adminchen5188jjs");
				if($data != 'HTTPSQS_GET_END'){
					$datas[] = $data;
				}
			}
			

			print_r($data);








			/*D("Rest")->querysql("update `jjs_contract` set is_del = 1 where id = ".$v["id"]);
			D("Rest")->querysql("update `jjs_user_finance` set bond = bond-'".($v["price"]*$v["quantity"])."' where user_id = ".$v["user_id"]);
			D("Rest")->querysql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$v["user_id"]."','5489','系统撤单','".($v["price"]*$v["quantity"])."','".date("Y-m-d H:i:s")."')");
			if($v["cate"] == 0)
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$v["g_code"]."-B-".$v["price"]."-".strtotime($v["ctime"])."-".$v["user_id"]."&opt=get&auth=adminchen5188jjs");
			elseif($v["cate"] == 1)
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$v["g_code"]."-S-".$v["price"]."-".strtotime($v["ctime"])."-".$v["user_id"]."&opt=get&auth=adminchen5188jjs");*/
		/*}
	}*/

	//开盘
	/*public function open()
	{
		$userhold = 0;
		$opentime = date("Y-m-d H:i:s");
		$res = D("Rest")->query("select * from `jjs_goods_realease`");
		foreach($res as $k=>$v){
			$newprice = D("Rest")->query("select price from `jjs_shares` where g_code = '".$v["g_code"]."' order by ctime desc limit 1");

			$r = D("Rest")->query("select sum(num) as total from `jjs_user_gcode` where g_code = '".$v["g_code"]."'");
			$surplus = $v["num"]-$r[0]["total"];
			D("Rest")->querysql("insert into `jjs_contract`(user_id,g_code,cate,quantity,price,lave_quantity,status,ctime) values('".$v["user_id"]."','".$v["g_code"]."','1','".$surplus."','".$$newprice['price']."','".$surplus."','-1','".$opentime."')");
			for($i=1;$i<=$surplus;$i++){
				file_get_contents("http://apistore.51daniu.cn:1218/?name=".$v["g_code"]."-S-".$v['price']."&opt=put&data=".$data."&auth=adminchen5188jjs");
			}
		}

		foreach($res as $k=>$v){
			
			D("Rest")->querysql("update `jjs_contract` set is_del = 1 where id = ".$v["id"]);
			D("Rest")->querysql("update `jjs_user_finance` set bond = bond-'".($v["price"]*$v["quantity"])."' where user_id = ".$v["user_id"]);
			D("Rest")->querysql("insert into `jjs_detail`(user_id,cate,remark,amount,ctime) values('".$v["user_id"]."','5489','系统撤单','".($v["price"]*$v["quantity"])."','".date("Y-m-d H:i:s")."')");
		}
	}*/

}
>>>>>>> .r437
