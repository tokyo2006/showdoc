<template>
  <div class="body">
    <!--header-->
    <div class="header">
      <div class="header-wrap">
        <a href="/">
          <div class="logo">
            <img class="logo-img" src="@/assets/Logo.svg" />
            <span class="logo-title">ShowDoc</span>
          </div>
        </a>
        <input type="checkbox" name id="mobile-menu-toggle" value />
        <label class="gh" for="mobile-menu-toggle">
          <span></span>
        </label>
        <div class="nav">
          <ul>
            <li>
              <router-link :to="link">{{ link_text }}</router-link>
            </li>
            <li v-if="publicSquareEnabled">
              <a @click="openPublicSquare" style="cursor: pointer;">{{ $t('public_square') }}</a>
            </li>
            <li>
              <a
                target="_blank"
                v-if="$lang == 'zh-cn'"
                href="https://www.showdoc.cc/clients"
                >客户端</a
              >
            </li>
          </ul>
        </div>
      </div>
    </div>

    <div class="hbanner">
      <div class="wrapper">
        <div class="hbanner-txt">
          <h2>
            {{ $t('section_description1_1') }}
            <br />
            <font class="f-blue">{{ $t('section_description1_2') }}</font>
          </h2>
          <div class="btns">
            <a
              href="https://www.showdoc.cc/demo"
              target="_blank"
              class="btn on"
              >{{ $t('demo') }}</a
            >
            <a href="https://www.showdoc.cc/help" target="_blank" class="btn">{{
              $t('more')
            }}</a>
          </div>
        </div>
        <div class="hbanner-imgs"></div>
      </div>
    </div>
    <div class="hrow hrow1">
      <div class="wrapper">
        <div class="img fl">
          <img src="static/imgs/home2.png" />
          <div class="box">
            <img src="static/imgs/home2-img.png" />
          </div>
        </div>
        <div class="txt fr">
          <h2>{{ $t('section_title2') }}</h2>
          <div class="desc">
            <p>
              <img src="static/imgs/Vector.png" />
              {{ $t('section_description2_1') }}
            </p>
            <p>
              <img src="static/imgs/Vector.png" />
              {{ $t('section_description2_2') }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="hrow hrow2">
      <div class="wrapper">
        <div class="img fr">
          <img src="static/imgs/home3.png" />
        </div>
        <div class="txt fl">
          <h2>{{ $t('section_title3') }}</h2>
          <div class="desc">
            <p>
              <img src="static/imgs/Vector1.png" />
              {{ $t('section_description3_1') }}
            </p>
            <p>
              <img src="static/imgs/Vector1.png" />
              {{ $t('section_description3_2') }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="hrow hrow3">
      <div class="wrapper">
        <div class="img fl">
          <img src="static/imgs/home4.png" />
        </div>
        <div class="txt fr">
          <h2>{{ $t('section_title4') }}</h2>
          <div class="desc">
            <p>
              <img src="static/imgs/Vector.png" />
              {{ $t('section_description4_1') }}
            </p>
            <p>
              <img src="static/imgs/Vector.png" />
              {{ $t('section_description4_2') }}
            </p>
          </div>
        </div>
      </div>
    </div>

    <div class="hrow hrow4">
      <div class="wrapper">
        <div class="img fr">
          <img src="static/imgs/home5.png" />
        </div>
        <div class="txt fl">
          <h2>{{ $t('section_title5') }}</h2>
          <div class="desc">
            <p>{{ $t('section_description5') }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="hrow hrow5">
      <div class="wrapper">
        <div class="img fl">
          <img src="static/imgs/home6.png" />
        </div>
        <div class="txt fr">
          <h2>{{ $t('section_title6') }}</h2>
          <div class="desc">
            <p>
              <img src="static/imgs/Vector1.png" />
              {{ $t('section_description6_1') }}
            </p>
            <p>
              <img src="static/imgs/Vector1.png" />
              {{ $t('section_description6_2') }}
            </p>
          </div>
        </div>
      </div>
    </div>
    <div class="hrow hrow6">
      <div class="wrapper">
        <div class="img fr">
          <img src="static/imgs/home7.png" />
        </div>
        <div class="txt fl">
          <h2>{{ $t('section_title7') }}</h2>
          <div class="desc">
            <p>{{ $t('section_description7_1') }}</p>
            <p>{{ $t('section_description7_2') }}</p>
          </div>
        </div>
      </div>
    </div>

    <div class="hrow hrow7">
      <div class="wrapper">
        <div class="img fl">
          <img src="static/imgs/home8.png" />
        </div>
        <div class="txt fr">
          <h2>{{ $t('section_title8') }}</h2>
          <div class="desc">
            <p>{{ $t('section_description8_1') }}</p>
            <p>{{ $t('section_description8_2') }}</p>
          </div>
        </div>
      </div>
    </div>
    <div class="hfoot">
      <div class="wrapper">
        <h2>{{ $t('section_description9') }}</h2>
        <p class="case-logo">
          <img src="static/imgs/case-logo/tencent.png" />
          <img src="static/imgs/case-logo/huawei_logo.png" />
          <img src="static/imgs/case-logo/baidu.png" />
          <img src="static/imgs/case-logo/jingdong.png" />
          <img src="static/imgs/case-logo/zijie.png" />
          <img src="static/imgs/case-logo/shunfeng.png" />
        </p>
        <router-link to="/user/login" class="btn">{{
          $t('section_title9')
        }}</router-link>
      </div>
      <div class="copyright">
        <a href="https://beian.miit.gov.cn/">{{ beian }}</a>
      </div>
    </div>
  </div>
</template>

<script>
import { getUserInfo } from '@/models/user'
export default {
  name: 'Index',
  data() {
    return {
      height: '',
      link: '',
      link_text: '',
      beian: '',
      publicSquareEnabled: false
    }
  },
  methods: {
    getHeight() {
      var winHeight
      if (window.innerHeight) {
        winHeight = window.innerHeight
      } else if (document.body && document.body.clientHeight) {
        winHeight = document.body.clientHeight
      }
      this.height = winHeight + 'px'
    },
    homePageSetting() {
      var url = '/api/common/homePageSetting'
      this.request(url, this.form,'post',false).then(data => {
        if (data.error_code === 0) {
          this.beian = data.data.beian
          if (data.data.home_page == 2) {
            // 跳转到登录页面
            this.$router.replace({
              path: '/user/login'
            })
          }
          if (
            data.data.home_page == 3 &&
            data.data.home_item
          ) {
            // 跳转到指定项目
            this.$router.replace({
              path: '/' + data.data.home_item
            })
          }
        }
      })
    },
    checkPublicSquareEnabled() {
      this.request('/api/publicSquare/checkEnabled', {})
        .then(data => {
          this.publicSquareEnabled = data.data.enable === 1
        })
        .catch(() => {
          this.publicSquareEnabled = false
        })
    },
    openPublicSquare() {
      const routeData = this.$router.resolve({ path: '/public-square/index' });
      window.open(routeData.href, '_blank');
    }
  },
  mounted() {
    this.getHeight()
    this.homePageSetting()
    this.checkPublicSquareEnabled()
    this.link = '/user/login'
    this.link_text = this.$t('index_login_or_register')
    getUserInfo(data => {
      if (data.error_code === 0) {
        this.link = '/item/index'
        this.link_text = this.$t('my_item')
      }
    })
  }
}
</script>

<!-- Add "scoped" attribute to limit CSS to this component only -->
<style scoped src="@/../static/css/qietu.css"></style>
<style scoped src="@/../static/css/style.css"></style>
<style scoped src="@/../static/css/responsive.css"></style>
<style scoped>
.case-logo img {
  width: 200px;
  padding: 20px;
}
</style>
