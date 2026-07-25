# Changelog

本项目遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/) 的记录方式，并使用语义化版本的发布原则。

## [Unreleased]

暂无未发布变更。

## [2.2.1] - 2026-07-25

### Fixed

- 修正 SeAT 插件元数据中的仓库 URL 为空的问题，使模块版本页可显示项目 GitHub 链接。

## [2.2.0] - 2026-07-25

### Added

- 令牌审查的最后离线显示、筛选与 CSV 列；
- 军团审计九列交易视图、成员合同物品摘要、合同详情弹窗与关键词筛选；
- 军团审计页的 Unknown 角色、实体、军团及联盟名称异步解析；
- 安装、升级、运行、用户使用、令牌安全与架构的分层文档体系。

### Changed

- 军团审计的页面和 CSV 按 `direction` 显式映射发起方与接收方，并使用审计快照军团显示历史关系；
- README 重构为生产部署入口，正式安装与升级路径统一使用受限插件 migration。

## [2.1.1] - 2026-07-23

### Fixed

- 修正令牌审查在角色级筛选后误判 SeAT 用户主角色是否属于当前军团范围的问题。

## [2.1] - 2026-07-23

### Added

- 固定军团的 ISK Donation 与成员低价合同审计；
- 军团审计页面、异步扫描进度与 CSV 导出；
- 只读令牌审查页面与 CSV 导出。

### Changed

- 扩展审计权限，使查看和管理职责分离；
- 为军团审计引入独立 cursor、幂等来源事件键与受审军团配置。

[Unreleased]: https://github.com/akinams053/seat-audit-monitor/compare/2.2.1...HEAD
[2.2.1]: https://github.com/akinams053/seat-audit-monitor/compare/2.2.0...2.2.1
[2.2.0]: https://github.com/akinams053/seat-audit-monitor/compare/2.1.1...2.2.0
[2.1.1]: https://github.com/akinams053/seat-audit-monitor/compare/2.1...2.1.1
[2.1]: https://github.com/akinams053/seat-audit-monitor/releases/tag/2.1
