<?php
namespace BIAPSU\ProfileSync\Tests;
use BIAPSU\ProfileSync\{Account_Linking,Settings,Frontend};
use PHPUnit\Framework\TestCase;
class P2LinkingTest extends TestCase {
 protected function setUp(): void { bia_test_reset(); }
 /** @runInSeparateProcess
  * @preserveGlobalState disabled
  */
 public function test_recognized_marker_without_intent_fails_closed(): void {
  session_start();$_SESSION=array();
  $service=new Account_Linking(new Settings());
  $service->filter_return_to('https://example.test/profile-sync/?biapsu_link=recognized',array(),new class{public function id(){return 'oidc';}});
  $result=$service->pre_resolve_user(null,(object)array('provider'=>'oidc','sub'=>'subject'),array());
  $this->assertInstanceOf(\WP_Error::class,$result);
  $this->assertSame('biapsu_link_invalid_intent',$result->get_error_code());
 }
 public function test_slug_collision_is_preserved_and_new_page_is_valid(): void {
  $existing=bia_test_make_page('profile-sync');
  $GLOBALS['__mock_posts'][$existing]->post_content='Custom content';
  $s=new Settings();$f=new Frontend($s);$id=$f->ensure_page();
  $this->assertNotSame($existing,$id);
  $this->assertSame('Custom content',get_post($existing)->post_content);
  $this->assertStringContainsString('[biapsu_profilesync]',get_post($id)->post_content);
 }
 /** @dataProvider invalidPages */
 public function test_invalid_stored_page_is_replaced($type,$status,$content): void {
  $id=bia_test_make_page('profile-sync');$p=$GLOBALS['__mock_posts'][$id];$p->post_type=$type;$p->post_status=$status;$p->post_content=$content;
  $s=new Settings();$v=$s->all();$v['choice_page_id']=$id;$s->save($v);
  $this->assertNotSame($id,(new Frontend($s))->ensure_page());
 }
 public static function invalidPages(): array {return array(array('page','trash','[biapsu_profilesync]'),array('page','draft','[biapsu_profilesync]'),array('post','publish','[biapsu_profilesync]'),array('page','publish','Unrelated'));}
 /** @runInSeparateProcess
  * @preserveGlobalState disabled
  * @dataProvider intentCases
  */
 public function test_intent_failures_and_normal_login_are_distinct($kind,$expect_error): void {
  session_start();define('LOGGED_IN_COOKIE','test_cookie');
  bia_test_make_user(17);update_user_meta(17,'authorizenter_answers',array('is_bia_volunteer'=>'ใช่'));
  $_COOKIE['test_cookie']='source';$GLOBALS['__cookie_users']=array('source'=>17,'switched'=>18,'renewed'=>17);
  $url='https://example.test/profile-sync/?biapsu_link=random';
  $_SESSION[Account_Linking::INTENT_SESSION_KEY]=array('return_to'=>$url,'user_id'=>17,'created'=>time(),'cookie_digest'=>hash('sha256','source'));
  if('expired'===$kind){$_SESSION[Account_Linking::INTENT_SESSION_KEY]['created']=time()-601;}
  if('revoked'===$kind){$GLOBALS['__cookie_users']=array();}
  if(in_array($kind,array('switched','renewed'),true)){$_COOKIE['test_cookie']=$kind;}
  if('missing-cookie'===$kind){$_COOKIE=array();}
  if('cancelled'===$kind){$_SESSION=array();}
  if('normal'===$kind){$url='https://example.test/course/';}
  $service=new Account_Linking(new Settings());$service->filter_return_to($url,array(),new class{public function id(){return 'oidc';}});
  $result=$service->pre_resolve_user(null,(object)array('provider'=>'oidc','sub'=>'subject','email'=>'different@example.test'),array());
  if($expect_error){$this->assertInstanceOf(\WP_Error::class,$result);$this->assertSame('biapsu_link_invalid_intent',$result->get_error_code());}
  elseif('normal'===$kind){$this->assertNull($result);}
  else{$this->assertInstanceOf(\WP_User::class,$result);$this->assertSame(17,$result->ID);}
  $this->assertSame('',get_user_meta(17,Account_Linking::LINK_META,true));
 }
 public static function intentCases(): array {return array(array('expired',true),array('revoked',true),array('switched',true),array('renewed',true),array('missing-cookie',true),array('cancelled',true),array('valid',false),array('normal',false));}
 public function test_claim_failure_is_returned_before_login(): void {
  bia_test_make_user(17);update_user_meta(17,'authorizenter_answers',array('is_bia_volunteer'=>'ใช่'));
  $service=new Account_Linking(new Settings());$prop=new \ReflectionProperty($service,'validated_user_id');$prop->setValue($service,17);
  $identity=(object)array('provider'=>'oidc','sub'=>'subject');$user=$service->pre_resolve_user(null,$identity,array());
  $GLOBALS['__claim_link_callback']=static function(){return new \WP_Error('authorizenter_link_write_failed','Synthetic DB failure');};
  $this->assertInstanceOf(\WP_Error::class,$service->before_login($user,$identity,array()));$this->assertSame('',get_user_meta(17,Account_Linking::LINK_META,true));
 }

 public function test_setup_requires_oidc_issuer(): void {
  $GLOBALS['__bia_core']=(object)array('settings'=>new class{public function get_context($id){return array();}},'providers'=>new class{public function enabled_for_context($context){return array('oidc'=>new class{public function oidc_provider_url(){return '';}});}});
  $this->assertFalse((new Account_Linking(new Settings()))->configuration_ready());
 }

}
