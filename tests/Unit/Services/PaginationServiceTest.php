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

            // 应包含每一页的链接
            $this->assertStringContainsString('/post_list?page=1', $html);
            $this->assertStringContainsString('/post_list?page=2', $html);
            $this->assertStringContainsString('/post_list?page=5', $html);

            // 当前页应带有激活样式
            $this->assertStringContainsString('>2</a>', $html);
            $this->assertStringContainsString('bg-blue-500 text-white border-blue-500', $html);

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
            // 构造较多页码，触发省略号逻辑
            $html = PaginationService::generatePagination(10, 1000, 10, 'post_list', [], 10);

            // 应出现省略号
            $this->assertStringContainsString('>...</span>', $html);

            // 始终显示第一页和最后一页
            $this->assertStringContainsString('/post_list?page=1', $html);
            $this->assertStringContainsString('/post_list?page=100', $html);
        }
    }
}
