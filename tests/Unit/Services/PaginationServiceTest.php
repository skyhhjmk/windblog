<?php

// 在 app\service 命名空间下定义一个 route 辅助函数的桩，用于分页链接生成测试

namespace app\service {
    function route(string $name, array $params = []): string
    {
        $query = http_build_query($params);

        return '/' . $name . ($query ? ('?' . $query) : '');
    }
}

namespace Tests\Unit\Services {

    use app\service\PaginationService;
    use PHPUnit\Framework\TestCase;

    class PaginationServiceTest extends TestCase
    {
        public function testNoPaginationWhenTotalItemsLessOrEqualPerPage(): void
        {
            $html = PaginationService::generatePagination(1, 10, 10, 'post_list');
            $this->assertSame('', $html);
        }

        public function testBasicPaginationLinksAndActivePage(): void
        {
            // 总共 50 条，每页 10 条，共 5 页
            $html = PaginationService::generatePagination(2, 50, 10, 'post_list');

            // 应包含其他页的链接（当前页除外）
            $this->assertStringContainsString('/post_list?page=1', $html);
            $this->assertStringContainsString('/post_list?page=3', $html);
            $this->assertStringContainsString('/post_list?page=5', $html);

            // 当前页（第2页）应带有激活样式，且为 span 而非链接
            $this->assertStringContainsString('wind-blog-n-active', $html);
            $this->assertStringContainsString('<span class="wind-blog-n-number">2</span>', $html);

            // 上一页/下一页链接应存在
            $this->assertStringContainsString('rel="prev"', $html);
            $this->assertStringContainsString('rel="next"', $html);
        }

        public function testPreviousButtonDisabledOnFirstPage(): void
        {
            $html = PaginationService::generatePagination(1, 50, 10, 'post_list');

            // 第一页时不应有 rel="prev" 链接，而是禁用的 span
            $this->assertStringNotContainsString('rel="prev"', $html);
            $this->assertStringContainsString('aria-disabled="true"', $html);
        }

        public function testNextButtonDisabledOnLastPage(): void
        {
            $html = PaginationService::generatePagination(5, 50, 10, 'post_list');

            // 最后一页时不应有 rel="next" 链接，而是禁用的 span
            $this->assertStringNotContainsString('rel="next"', $html);
            $this->assertStringContainsString('aria-disabled="true"', $html);
        }

        public function testEllipsisAppearsWhenManyPages(): void
        {
            // 构造较多页码（100页），maxDisplayPages=10，当前在第10页
            $html = PaginationService::generatePagination(10, 1000, 10, 'post_list', [], 10);

            // Wind-BLOG 分页不使用省略号，而是显示滑动窗口的页码
            // 应包含当前页及其附近的页码
            $this->assertStringContainsString('<span class="wind-blog-n-number">10</span>', $html);
            $this->assertStringContainsString('wind-blog-n-active', $html);

            // 应包含窗口范围内的页码链接（第10页附近的页）
            $this->assertStringContainsString('/post_list?page=9', $html);
            $this->assertStringContainsString('/post_list?page=11', $html);

            // 首页和末页按钮应始终存在
            $this->assertStringContainsString('aria-label="第一页"', $html);
            $this->assertStringContainsString('aria-label="最后一页"', $html);
            $this->assertStringContainsString('/post_list?page=1', $html);
            $this->assertStringContainsString('/post_list?page=100', $html);
        }
    }
}
