// Programmatic APIs (ElMessage / ElMessageBox) are imported from 'element-plus'
// directly, so unplugin-vue-components never injects their styles. Keep these
// side-effect imports so confirm/prompt overlays stay centered and styled.
import 'element-plus/es/components/message/style/css'
import 'element-plus/es/components/message-box/style/css'
