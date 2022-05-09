panel.plugin('d4l/static-site-generator', {
  fields: {
    staticSiteGenerator: {
      props: {
        label: String,
        endpoint: String,
        help: {
          type: String,
          default: 'Click the button to generate a static version of the website.',
        },
        progress: {
          type: String,
          default: 'Please wait...',
        },
        success: {
          type: String,
          default: 'Static site successfully generated',
        },
        error: {
          type: String,
          default: 'An error occurred',
        },
      },
      data() {
        return {
          isBusy: false,
          response: null,
        };
      },
      template: `
        <div class="d4l-static-site-generator">
          <k-box class="d4l-static-site-generator__container" v-if="!response && !isBusy" theme="regular">
            <k-form @submit="execute()">
              <k-text theme="help" class="d4l-static-site-generator__help">
              {{ help.replace(/<\\/?p>/g, '') }}
              </k-text>
              <k-button type="submit" icon="upload" theme="negative" class="d4l-static-site-generator__execute">
                {{ label }}
              </k-button>
            </k-form>
          </k-box>

          <k-box v-if="isBusy" class="d4l-static-site-generator__status" theme="regular">
            <k-text>{{ progress }}</k-text>
          </k-box>
          <k-box v-if="response && response.success" class="d4l-static-site-generator__status" theme="positive">
            <k-text>{{ success }}</k-text>
            <k-text v-if="response.message" class="d4l-static-site-generator__message" theme="help">{{ response.message }}</k-text>
          </k-box>
          <k-box v-if="response && !response.success" class="d4l-static-site-generator__status" theme="negative">
            <k-text>{{ error }}</k-text>
            <k-text v-if="response.message" class="d4l-static-site-generator__message" theme="help">{{ response.message }}</k-text>
          </k-box>
        </div>
      `,
      methods: {
        async execute() {
          const { endpoint } = this.$props;
          if (!endpoint) {
            throw new Error('Error: Config option "d4l.static_site_generator.endpoint" is missing or null. Please set this to any string, e.g. "generate-static-site".');
          }

          this.isBusy = true;
          const response = await this.$api.post(`${endpoint}`);
          this.isBusy = false;
          this.response = response;
        },
      },
    },
  },
});
